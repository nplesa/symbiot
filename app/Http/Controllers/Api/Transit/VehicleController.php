<?php

namespace App\Http\Controllers\Api\Transit;

use App\Http\Controllers\Controller;
use App\Transit\Models\TransitFeed;
use App\Transit\Realtime\VehicleStore;
use App\Transit\Services\FeedDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class VehicleController extends Controller
{
    private const MAX_VEHICLES = 600;

    public function __invoke(Request $request, FeedDiscovery $discovery, VehicleStore $store): JsonResponse
    {
        $v = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:100|max:10000',
        ]);
        $lat = (float) $v['lat'];
        $lon = (float) $v['lon'];
        $radius = (int) ($v['radius'] ?? 3000);

        $feeds = $discovery->coverageFor($lat, $lon)
            ->filter(fn (TransitFeed $feed): bool => $feed->vehicle_positions_url !== null && $feed->static_hash !== null);

        // Looking at the map is what makes the poller fetch this feed.
        $feeds->each(function (TransitFeed $feed): void {
            if ($feed->requested_at === null || $feed->requested_at->lt(now()->subMinute())) {
                $feed->forceFill(['requested_at' => now()])->saveQuietly();
            }
        });

        $dLat = $radius / 111320;
        $dLon = $radius / (111320 * max(0.1, cos(deg2rad($lat))));
        $vehicles = [];
        $updated = null;

        try {
            foreach ($feeds as $feed) {
                $updated = max($updated ?? 0, $store->updatedAt($feed->id) ?? 0) ?: null;
                foreach ($store->all($feed->id) as $vehicle) {
                    if (abs($vehicle['lat'] - $lat) <= $dLat && abs($vehicle['lon'] - $lon) <= $dLon) {
                        $vehicles[] = $vehicle;
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);

            return response()->json(['available' => false, 'vehicles' => [], 'updated_at' => null]);
        }

        return response()->json([
            'available' => $feeds->isNotEmpty(),
            'vehicles' => array_slice($vehicles, 0, self::MAX_VEHICLES),
            'updated_at' => $updated,
        ]);
    }
}
