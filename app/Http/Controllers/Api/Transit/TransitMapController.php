<?php

namespace App\Http\Controllers\Api\Transit;

use App\Http\Controllers\Controller;
use App\Transit\Models\TransitFeed;
use App\Transit\Services\FeedDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransitMapController extends Controller
{
    private const MAX_STOPS = 3000;

    public function stops(Request $request, FeedDiscovery $discovery): JsonResponse
    {
        $v = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:100|max:15000',
        ]);
        $lat = (float) $v['lat'];
        $lon = (float) $v['lon'];
        $radius = (int) ($v['radius'] ?? 1500);

        $feedIds = $discovery->coverageFor($lat, $lon)
            ->filter(fn (TransitFeed $feed): bool => $feed->static_hash !== null)
            ->pluck('id');

        $dLat = $radius / 111320;
        $dLon = $radius / (111320 * max(0.1, cos(deg2rad($lat))));

        $stops = DB::table('transit_stops')
            ->whereIn('feed_id', $feedIds)
            ->whereBetween('lat', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('lon', [$lon - $dLon, $lon + $dLon])
            ->where(fn ($q) => $q->whereNull('location_type')->orWhereIn('location_type', [0, 1]))
            ->get(['feed_id', 'stop_id', 'name', 'lat', 'lon'])
            // The query above selects a square; keep only stops inside the circle.
            ->filter(fn ($s): bool => $this->metersBetween($lat, $lon, (float) $s->lat, (float) $s->lon) <= $radius)
            ->sortBy(fn ($s): float => (($s->lat - $lat) ** 2) + (($s->lon - $lon) ** 2))
            ->take(self::MAX_STOPS)
            ->values();

        return response()->json([
            'stops' => $stops->map(fn ($s): array => [
                'feed_id' => (int) $s->feed_id,
                'stop_id' => $s->stop_id,
                'name' => $s->name,
                'lat' => (float) $s->lat,
                'lon' => (float) $s->lon,
            ])->all(),
            'truncated' => $stops->count() >= self::MAX_STOPS,
        ]);
    }

    private function metersBetween(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371000 * 2 * asin(min(1, sqrt($a)));
    }

    public function stopRoutes(int $feed, string $stop): JsonResponse
    {
        // Stations (location_type 1) have no stop_times; their platforms do.
        $stopIds = DB::table('transit_stops')->where('feed_id', $feed)->where('parent_station', $stop)->pluck('stop_id')->push($stop)->all();

        $routes = DB::table('transit_stop_times as st')
            ->join('transit_trips as t', fn ($j) => $j->on('t.feed_id', '=', 'st.feed_id')->on('t.trip_id', '=', 'st.trip_id'))
            ->join('transit_routes as r', fn ($j) => $j->on('r.feed_id', '=', 't.feed_id')->on('r.route_id', '=', 't.route_id'))
            ->where('st.feed_id', $feed)
            ->whereIn('st.stop_id', $stopIds)
            ->distinct()
            ->orderBy('r.route_type')
            ->orderBy('r.short_name')
            ->limit(80)
            ->get(['r.route_id', 'r.short_name', 'r.long_name', 'r.route_type', 'r.color', 'r.text_color']);

        return response()->json([
            'routes' => $routes->map(fn ($r): array => [
                'route_id' => $r->route_id,
                'short_name' => $r->short_name,
                'long_name' => $r->long_name,
                'route_type' => (int) $r->route_type,
                'color' => $r->color,
                'text_color' => $r->text_color,
            ])->all(),
        ]);
    }

    public function routeShape(int $feed, string $route): JsonResponse
    {
        $shapeIds = DB::table('transit_trips')
            ->where('feed_id', $feed)
            ->where('route_id', $route)
            ->whereNotNull('shape_id')
            ->select('direction_id', 'shape_id', DB::raw('count(*) as trips'))
            ->groupBy('direction_id', 'shape_id')
            ->orderByDesc('trips')
            ->get()
            ->groupBy(fn ($row) => (string) $row->direction_id)
            ->map(fn ($rows) => $rows->first()->shape_id)
            ->values();

        // OSM routes may be split into several disconnected pieces named "{id}~{n}".
        $shapes = DB::table('transit_shapes')
            ->where('feed_id', $feed)
            ->where(function ($query) use ($shapeIds): void {
                $query->whereIn('shape_id', $shapeIds);
                foreach ($shapeIds as $shapeId) {
                    $query->orWhere('shape_id', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $shapeId) . '~%');
                }
            })
            ->pluck('points')
            ->map(fn (string $points): array => json_decode($points, true) ?: [])
            ->filter()
            ->values();

        return response()->json(['shapes' => $shapes->all()]);
    }
}
