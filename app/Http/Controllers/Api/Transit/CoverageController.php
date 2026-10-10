<?php

namespace App\Http\Controllers\Api\Transit;

use App\Http\Controllers\Controller;
use App\Transit\Models\TransitFeed;
use App\Transit\Services\FeedDiscovery;
use App\Transit\Services\FeedProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoverageController extends Controller
{
    public function __invoke(Request $request, FeedDiscovery $discovery, FeedProgress $progress): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:100|max:15000',
        ]);

        $feeds = $discovery->ensureImported(
            (float) $validated['lat'],
            (float) $validated['lon'],
            isset($validated['radius']) ? (int) $validated['radius'] : null,
        );

        return response()->json([
            'feeds' => $feeds->map(fn (TransitFeed $feed): array => [
                'id' => $feed->id,
                'name' => $feed->name,
                'city' => $feed->city,
                'country_code' => $feed->country_code,
                'status' => $feed->import_status,
                'has_data' => $feed->static_hash !== null,
                'realtime' => $feed->vehicle_positions_url !== null,
                'last_imported_at' => $feed->last_imported_at?->toIso8601String(),
                'license' => $feed->license,
                'progress' => $this->progressFor($feed, $progress),
            ])->all(),
            'preparing' => $feeds->contains(fn (TransitFeed $feed): bool => in_array($feed->import_status, ['pending', 'queued', 'importing'], true)),
        ]);
    }

    /** @return array{stage: string, percent: int, detail: ?string}|null */
    private function progressFor(TransitFeed $feed, FeedProgress $progress): ?array
    {
        if ($feed->import_status === 'queued') {
            $stored = $progress->get($feed);
            // A fresh queue entry must not show leftovers from a previous run.
            $fresh = $stored && $stored['stage'] !== 'done' && $stored['stage'] !== 'failed';

            return $fresh ? $stored : ['stage' => 'queued', 'percent' => 0, 'detail' => null];
        }
        if ($feed->import_status === 'importing') {
            return $progress->get($feed) ?? ['stage' => 'downloading', 'percent' => 1, 'detail' => null];
        }
        if ($feed->import_status === 'imported') {
            return ['stage' => 'done', 'percent' => 100, 'detail' => null];
        }
        if ($feed->import_status === 'failed') {
            return ['stage' => 'failed', 'percent' => 0, 'detail' => $feed->import_error];
        }

        return null;
    }
}
