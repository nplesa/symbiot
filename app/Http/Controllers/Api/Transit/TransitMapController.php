<?php

namespace App\Http\Controllers\Api\Transit;

use App\Http\Controllers\Controller;
use App\Transit\Models\TransitFeed;
use App\Transit\Services\FeedDiscovery;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransitMapController extends Controller
{
    private const MAX_STOPS = 15000;

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

        $feedIds = $discovery->coverageWithinRadius($lat, $lon, $radius)
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

    public function nearestNextDeparture(Request $request, FeedDiscovery $discovery): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'route_ref' => 'required|string|max:100',
        ]);
        $lat = (float) $data['lat'];
        $lon = (float) $data['lon'];
        $routeRef = mb_strtolower(trim($data['route_ref']));
        $feeds = $discovery->ensureImported($lat, $lon);
        $feedIds = $feeds
            ->filter(fn (TransitFeed $feed): bool => $feed->provider !== 'osm' && $feed->static_hash !== null)
            ->pluck('id');

        if ($feedIds->isEmpty()) {
            if ($feeds->contains(fn (TransitFeed $feed): bool => in_array($feed->import_status, ['pending', 'queued', 'importing'], true))) {
                $reason = 'feed_importing';
            } elseif ($feeds->contains(fn (TransitFeed $feed): bool => $feed->import_status === 'failed')) {
                $reason = 'feed_import_failed';
            } else {
                $reason = 'no_schedule_feed';
            }

            return response()->json(['departure' => null, 'reason' => $reason]);
        }

        $radius = 500;
        $dLat = $radius / 111320;
        $dLon = $radius / (111320 * max(0.1, cos(deg2rad($lat))));
        $candidates = DB::table('transit_stops as s')
            ->join('transit_stop_times as st', fn ($join) => $join->on('st.feed_id', '=', 's.feed_id')->on('st.stop_id', '=', 's.stop_id'))
            ->join('transit_trips as t', fn ($join) => $join->on('t.feed_id', '=', 'st.feed_id')->on('t.trip_id', '=', 'st.trip_id'))
            ->join('transit_routes as r', fn ($join) => $join->on('r.feed_id', '=', 't.feed_id')->on('r.route_id', '=', 't.route_id'))
            ->whereIn('s.feed_id', $feedIds)
            ->whereBetween('s.lat', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('s.lon', [$lon - $dLon, $lon + $dLon])
            ->whereRaw('LOWER(TRIM(r.short_name)) = ?', [$routeRef])
            ->distinct()
            ->limit(100)
            ->get(['s.feed_id', 's.stop_id', 's.name', 's.lat', 's.lon', 'r.route_id'])
            ->map(fn ($stop): array => [
                ...((array) $stop),
                'distance' => $this->metersBetween($lat, $lon, (float) $stop->lat, (float) $stop->lon),
            ])
            ->filter(fn (array $stop): bool => $stop['distance'] <= $radius)
            ->sortBy('distance')
            ->values();

        foreach ($candidates as $candidate) {
            $scheduleRequest = Request::create('/', 'GET', ['route_id' => $candidate['route_id']]);
            $schedule = $this->nextDeparture($scheduleRequest, (int) $candidate['feed_id'], (string) $candidate['stop_id'])->getData(true);
            if ($schedule['departure'] === null) {
                continue;
            }

            return response()->json([
                'departure' => $schedule['departure'],
                'stop_name' => $candidate['name'],
                'distance_meters' => (int) round($candidate['distance']),
            ]);
        }

        return response()->json([
            'departure' => null,
            'reason' => $candidates->isEmpty() ? 'no_matching_stop_or_route' : 'no_upcoming_departure',
        ]);
    }

    public function nextDeparture(Request $request, int $feed, string $stop): JsonResponse
    {
        $data = $request->validate([
            'route_id' => 'required|string|max:100',
        ]);

        // OSM route relations provide geometry and stop order, but never a timetable.
        // If the clicked OSM route has a matching GTFS route nearby, use its schedule.
        $feedRecord = TransitFeed::query()->find($feed);
        if ($feedRecord?->provider === 'osm') {
            $osmRoute = DB::table('transit_routes')
                ->where('feed_id', $feed)
                ->where('route_id', $data['route_id'])
                ->first(['short_name']);
            $osmStop = DB::table('transit_stops')
                ->where('feed_id', $feed)
                ->where('stop_id', $stop)
                ->first(['lat', 'lon']);

            if ($osmRoute?->short_name && $osmStop) {
                $radius = 500;
                $dLat = $radius / 111320;
                $dLon = $radius / (111320 * max(0.1, cos(deg2rad((float) $osmStop->lat))));
                $gtfsFeedIds = TransitFeed::query()
                    ->where('provider', 'gtfs')
                    ->whereNotNull('static_hash')
                    ->where('min_lat', '<=', (float) $osmStop->lat)
                    ->where('max_lat', '>=', (float) $osmStop->lat)
                    ->where('min_lon', '<=', (float) $osmStop->lon)
                    ->where('max_lon', '>=', (float) $osmStop->lon)
                    ->pluck('id');

                $matchingStops = DB::table('transit_stops as s')
                    ->join('transit_stop_times as st', fn ($join) => $join->on('st.feed_id', '=', 's.feed_id')->on('st.stop_id', '=', 's.stop_id'))
                    ->join('transit_trips as t', fn ($join) => $join->on('t.feed_id', '=', 'st.feed_id')->on('t.trip_id', '=', 'st.trip_id'))
                    ->join('transit_routes as r', fn ($join) => $join->on('r.feed_id', '=', 't.feed_id')->on('r.route_id', '=', 't.route_id'))
                    ->whereIn('s.feed_id', $gtfsFeedIds)
                    ->whereBetween('s.lat', [(float) $osmStop->lat - $dLat, (float) $osmStop->lat + $dLat])
                    ->whereBetween('s.lon', [(float) $osmStop->lon - $dLon, (float) $osmStop->lon + $dLon])
                    ->whereRaw('LOWER(TRIM(r.short_name)) = ?', [mb_strtolower(trim($osmRoute->short_name))])
                    ->distinct()
                    ->get(['s.feed_id', 's.stop_id', 's.name', 's.lat', 's.lon', 'r.route_id'])
                    ->map(fn ($candidate): array => [
                        ...((array) $candidate),
                        'distance' => $this->metersBetween((float) $osmStop->lat, (float) $osmStop->lon, (float) $candidate->lat, (float) $candidate->lon),
                    ])
                    ->filter(fn (array $candidate): bool => $candidate['distance'] <= $radius)
                    ->sortBy('distance');

                $next = null;
                foreach ($matchingStops as $candidate) {
                    $scheduleRequest = Request::create('/', 'GET', ['route_id' => $candidate['route_id']]);
                    $schedule = $this->nextDeparture($scheduleRequest, (int) $candidate['feed_id'], (string) $candidate['stop_id'])->getData(true);
                    if ($schedule['departure'] !== null
                        && ($next === null || $schedule['departure']['minutes_until'] < $next['departure']['minutes_until'])) {
                        $next = $schedule;
                    }
                }

                if ($next !== null) {
                    return response()->json($next);
                }
            }

            return response()->json(['departure' => null, 'reason' => 'schedule_unavailable']);
        }

        $stopIds = DB::table('transit_stops')
            ->where('feed_id', $feed)
            ->where('parent_station', $stop)
            ->pluck('stop_id')
            ->push($stop)
            ->all();
        $timezone = DB::table('transit_agencies')->where('feed_id', $feed)->value('timezone') ?: config('app.timezone');
        try {
            new DateTimeZone($timezone);
        } catch (\Exception) {
            $timezone = config('app.timezone');
        }
        $now = now($timezone);
        $activeDatesByService = [];

        for ($offset = -1; $offset <= 7; $offset++) {
            $serviceDate = $now->copy()->startOfDay()->addDays($offset);
            $date = $serviceDate->toDateString();
            $weekdayBit = 1 << ($serviceDate->dayOfWeekIso - 1);
            $activeServices = DB::table('transit_calendars')
                ->where('feed_id', $feed)
                ->whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date)
                ->whereRaw('(days_mask & ?) != 0', [$weekdayBit])
                ->pluck('service_id')
                ->flip();

            foreach (DB::table('transit_calendar_dates')
                ->where('feed_id', $feed)
                ->whereDate('date', $date)
                ->get(['service_id', 'exception_type']) as $exception) {
                if ((int) $exception->exception_type === 1) {
                    $activeServices->put($exception->service_id, true);
                } elseif ((int) $exception->exception_type === 2) {
                    $activeServices->forget($exception->service_id);
                }
            }

            foreach ($activeServices->keys() as $serviceId) {
                $activeDatesByService[$serviceId][] = $serviceDate;
            }
        }

        if ($activeDatesByService === []) {
            return response()->json(['departure' => null]);
        }

        $times = DB::table('transit_stop_times as st')
            ->join('transit_trips as t', fn ($join) => $join->on('t.feed_id', '=', 'st.feed_id')->on('t.trip_id', '=', 'st.trip_id'))
            ->where('st.feed_id', $feed)
            ->where('t.route_id', $data['route_id'])
            ->whereIn('t.service_id', array_keys($activeDatesByService))
            ->whereIn('st.stop_id', $stopIds)
            ->where(fn ($query) => $query->whereNotNull('st.arrival_seconds')->orWhereNotNull('st.departure_seconds'))
            ->get([
                't.service_id',
                't.headsign',
                'st.arrival_seconds',
                'st.departure_seconds',
            ]);

        $next = null;
        foreach ($times as $time) {
            $seconds = $time->arrival_seconds ?? $time->departure_seconds;
            foreach ($activeDatesByService[$time->service_id] ?? [] as $serviceDate) {
                $departure = $serviceDate->copy()->addSeconds((int) $seconds);
                if ($departure->lt($now) || ($next !== null && ! $departure->lt($next['at']))) {
                    continue;
                }
                $next = ['at' => $departure, 'headsign' => $time->headsign];
            }
        }

        if ($next === null) {
            return response()->json(['departure' => null]);
        }

        return response()->json([
            'departure' => [
                'time' => $next['at']->format('H:i'),
                'minutes_until' => (int) ceil($now->diffInSeconds($next['at']) / 60),
                'headsign' => $next['headsign'],
                'timezone' => $timezone,
            ],
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
