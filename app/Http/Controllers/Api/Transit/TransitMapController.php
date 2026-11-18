<?php

namespace App\Http\Controllers\Api\Transit;

use App\Support\GeoDistance;
use App\Http\Controllers\Controller;
use App\Transit\Models\TransitFeed;
use App\Transit\Services\FeedData;
use App\Transit\Services\FeedDiscovery;
use App\Transit\Services\TicketInfo;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransitMapController extends Controller
{
    /** Nearest schedule candidates checked by nearestNextDeparture (each one runs several queries). */
    private const MAX_DEPARTURE_CANDIDATES = 10;

    /** Clients always see public feed ids; the GTFS rows live under each feed's data feed id. */
    public function __construct(private readonly FeedData $feedData) {}

    /**
     * SQL ORDER BY expression for the squared equirectangular distance to ($lat, $lon).
     * Plain arithmetic, so it works on MariaDB and SQLite; exact distances are checked in PHP.
     *
     * @return array{0: string, 1: list<float>}
     */
    private function distanceOrder(string $alias, float $lat, float $lon): array
    {
        $lonScale = cos(deg2rad($lat)) ** 2;

        return [
            "(({$alias}.lat - ?) * ({$alias}.lat - ?)) + (({$alias}.lon - ?) * ({$alias}.lon - ?) * ?)",
            [$lat, $lat, $lon, $lon, $lonScale],
        ];
    }

    public function stops(Request $request, FeedDiscovery $discovery): JsonResponse
    {
        $v = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:100|max:35000',
            'type' => 'nullable|in:bus,train',
        ]);
        $lat = (float) $v['lat'];
        $lon = (float) $v['lon'];
        $radius = (int) ($v['radius'] ?? 1500);
        $routeTypes = match ($v['type'] ?? null) {
            'bus' => [3, ...range(700, 799)],
            'train' => [2, ...range(100, 199)],
            default => null,
        };

        $feedIds = $discovery->coverageWithinRadius($lat, $lon, $radius)
            ->filter(fn (TransitFeed $feed): bool => $feed->static_hash !== null)
            ->pluck('id');
        $dataIds = $this->feedData->dataIds($feedIds);
        $publicIds = $this->feedData->publicIds($dataIds);
        $dLat = $radius / 111320;
        $dLon = $radius / (111320 * max(0.1, cos(deg2rad($lat))));
        $maxStops = max(1, (int) config('transit.max_map_stops'));
        [$distanceSql, $distanceBindings] = $this->distanceOrder('s', $lat, $lon);

        // Nearest first with a SQL LIMIT, so dense areas never load every stop of the
        // search square; one extra row tells whether the result was truncated.
        $stopsQuery = DB::table('transit_stops as s')->whereIn('s.feed_id', array_values($dataIds));
        if ($routeTypes !== null) {
            // Return a stop only when it is served by the selected mode. GTFS feeds
            // can overlap (for example a railway feed and a regional bus feed).
            $stopsQuery->whereExists(function ($query) use ($routeTypes): void {
                $query->selectRaw('1')
                    ->from('transit_stop_times as st')
                    ->join('transit_trips as t', fn ($join) => $join
                        ->on('t.feed_id', '=', 'st.feed_id')
                        ->on('t.trip_id', '=', 'st.trip_id'))
                    ->join('transit_routes as r', fn ($join) => $join
                        ->on('r.feed_id', '=', 't.feed_id')
                        ->on('r.route_id', '=', 't.route_id'))
                    ->whereColumn('st.feed_id', 's.feed_id')
                    ->whereColumn('st.stop_id', 's.stop_id')
                    ->whereIn('r.route_type', $routeTypes);
            });
        }

        $stops = $stopsQuery
            ->whereBetween('s.lat', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('s.lon', [$lon - $dLon, $lon + $dLon])
            ->where(function ($query): void {
                $query->whereNull('s.location_type')
                    ->orWhere('s.location_type', 0)
                    ->orWhere(function ($station): void {
                        $station->where('s.location_type', 1)
                            ->whereNotExists(function ($platform): void {
                                $platform->selectRaw('1')
                                    ->from('transit_stops as child')
                                    ->whereColumn('child.feed_id', 's.feed_id')
                                    ->whereColumn('child.parent_station', 's.stop_id')
                                    ->where('child.location_type', 0);
                            });
                    });
            })
            ->orderByRaw($distanceSql, $distanceBindings)
            ->limit($maxStops + 1)
            ->get(['s.feed_id', 's.stop_id', 's.name', 's.lat', 's.lon'])
            ->filter(fn ($s): bool => GeoDistance::meters($lat, $lon, (float) $s->lat, (float) $s->lon) <= $radius)
            ->values();
        $truncated = $stops->count() > $maxStops;
        $stops = $stops->take($maxStops);

        return response()->json([
            'stops' => $stops->map(fn ($s): array => [
                'feed_id' => $publicIds[(int) $s->feed_id] ?? (int) $s->feed_id,
                'stop_id' => $s->stop_id,
                'name' => $s->name,
                'lat' => (float) $s->lat,
                'lon' => (float) $s->lon,
            ])->all(),
            'truncated' => $truncated,
        ]);
    }

    public function stopRoutes(int $feed, string $stop, TicketInfo $tickets): JsonResponse
    {
        $feed = $this->feedData->dataId($feed);
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
            ->get(['r.route_id', 'r.short_name', 'r.long_name', 'r.route_type', 'r.color', 'r.text_color', 'r.agency_id']);

        return response()->json([
            // Where to buy tickets: links to the operators, from the feed (the app never sells tickets).
            'tickets' => $tickets->forStop($feed, $routes->pluck('agency_id')->unique()->values()->all()),
            'routes' => $routes->unique('route_id')->values()->map(fn ($r): array => [
                'route_id' => $r->route_id,
                'short_name' => $r->short_name,
                'long_name' => $r->long_name,
                'route_type' => (int) $r->route_type,
                'color' => $r->color,
                'text_color' => $r->text_color,
                // Matches tickets.operators[].agency_id: the line's own ticket links in its details card.
                'agency_id' => $r->agency_id,
            ])->all(),
        ]);
    }

    public function nearestNextDeparture(Request $request, FeedDiscovery $discovery): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'route_ref' => 'required|string|max:100',
            'mode' => 'nullable|in:subway',
            // The end the chosen line runs to (OSM "to"): a train in that direction is preferred.
            'towards' => 'nullable|string|max:150',
        ]);
        $lat = (float) $data['lat'];
        $lon = (float) $data['lon'];
        $routeRef = mb_strtolower(trim($data['route_ref']));
        $routeRefs = [$routeRef];
        $bareRouteRef = preg_replace('/^(?:(?:metro|metrou|subway|line|m|u|l)[\s_-]*)+/u', '', $routeRef) ?? $routeRef;
        if ($bareRouteRef !== '' && $bareRouteRef !== $routeRef) {
            $routeRefs[] = $bareRouteRef;
        }
        if (ctype_digit($bareRouteRef)) {
            foreach (['m', 'u', 'l', 'line ', 'metro ', 'metrou '] as $prefix) {
                $routeRefs[] = $prefix . $bareRouteRef;
            }
        }
        $routeRefs = array_values(array_unique($routeRefs));
        $feeds = $discovery->ensureImported($lat, $lon, userId: $request->user()->id);
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
        $dataIds = $this->feedData->dataIds($feedIds);
        $publicIds = $this->feedData->publicIds($dataIds);

        $radius = 500;
        $dLat = $radius / 111320;
        $dLon = $radius / (111320 * max(0.1, cos(deg2rad($lat))));
        [$distanceSql, $distanceBindings] = $this->distanceOrder('s', $lat, $lon);
        // Sort before LIMIT so the nearest stops are never cut off, and keep the list
        // short: every candidate below costs several schedule queries.
        $candidates = DB::table('transit_stops as s')
            ->join('transit_stop_times as st', fn ($join) => $join->on('st.feed_id', '=', 's.feed_id')->on('st.stop_id', '=', 's.stop_id'))
            ->join('transit_trips as t', fn ($join) => $join->on('t.feed_id', '=', 'st.feed_id')->on('t.trip_id', '=', 'st.trip_id'))
            ->join('transit_routes as r', fn ($join) => $join->on('r.feed_id', '=', 't.feed_id')->on('r.route_id', '=', 't.route_id'))
            ->whereIn('s.feed_id', array_values($dataIds))
            ->whereBetween('s.lat', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('s.lon', [$lon - $dLon, $lon + $dLon])
            ->where(function ($query) use ($routeRefs): void {
                $query->whereIn(DB::raw('LOWER(TRIM(r.short_name))'), $routeRefs)
                    ->orWhereIn(DB::raw('LOWER(TRIM(r.route_id))'), $routeRefs);
            })
            ->when(($data['mode'] ?? null) === 'subway', fn ($query) => $query->where(function ($types): void {
                $types->where('r.route_type', 1)->orWhereBetween('r.route_type', [400, 499]);
            }))
            ->distinct()
            ->orderByRaw($distanceSql, $distanceBindings)
            ->limit(self::MAX_DEPARTURE_CANDIDATES)
            ->get(['s.feed_id', 's.stop_id', 's.name', 's.lat', 's.lon', 'r.route_id'])
            ->map(fn ($stop): array => [
                ...((array) $stop),
                'distance' => GeoDistance::meters($lat, $lon, (float) $stop->lat, (float) $stop->lon),
            ])
            ->filter(fn (array $stop): bool => $stop['distance'] <= $radius)
            ->sortBy('distance')
            ->values();

        // The nearest stop with a departure; with "towards", the nearest one in that direction (a station
        // often has one platform per direction), else the nearest in any direction.
        $fallback = null;
        foreach ($candidates as $candidate) {
            $scheduleRequest = Request::create('/', 'GET', array_filter([
                'route_id' => $candidate['route_id'],
                'towards' => $data['towards'] ?? null,
            ]));
            $candidateFeed = $publicIds[(int) $candidate['feed_id']] ?? (int) $candidate['feed_id'];
            $schedule = $this->nextDeparture($scheduleRequest, $candidateFeed, (string) $candidate['stop_id'])->getData(true);
            if ($schedule['departure'] === null) {
                continue;
            }

            $found = [
                'departure' => $schedule['departure'],
                'stop_name' => $candidate['name'],
                'distance_meters' => (int) round($candidate['distance']),
            ];
            if (($schedule['departure']['direction_matched'] ?? null) !== false) {
                return response()->json($found);
            }
            $fallback ??= $found;
        }

        if ($fallback !== null) {
            return response()->json($fallback);
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
            'towards' => 'nullable|string|max:150',
        ]);

        $feedRecord = TransitFeed::query()->find($feed);
        $feed = $this->feedData->dataId($feedRecord ?? $feed);

        // OSM route relations provide geometry and stop order, but never a timetable.
        // If the clicked OSM route has a matching GTFS route nearby, use its schedule.
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
                $gtfsDataIds = $this->feedData->dataIds($gtfsFeedIds);
                $gtfsPublicIds = $this->feedData->publicIds($gtfsDataIds);

                $matchingStops = DB::table('transit_stops as s')
                    ->join('transit_stop_times as st', fn ($join) => $join->on('st.feed_id', '=', 's.feed_id')->on('st.stop_id', '=', 's.stop_id'))
                    ->join('transit_trips as t', fn ($join) => $join->on('t.feed_id', '=', 'st.feed_id')->on('t.trip_id', '=', 'st.trip_id'))
                    ->join('transit_routes as r', fn ($join) => $join->on('r.feed_id', '=', 't.feed_id')->on('r.route_id', '=', 't.route_id'))
                    ->whereIn('s.feed_id', array_values($gtfsDataIds))
                    ->whereBetween('s.lat', [(float) $osmStop->lat - $dLat, (float) $osmStop->lat + $dLat])
                    ->whereBetween('s.lon', [(float) $osmStop->lon - $dLon, (float) $osmStop->lon + $dLon])
                    ->whereRaw('LOWER(TRIM(r.short_name)) = ?', [mb_strtolower(trim($osmRoute->short_name))])
                    ->distinct()
                    ->get(['s.feed_id', 's.stop_id', 's.name', 's.lat', 's.lon', 'r.route_id'])
                    ->map(fn ($candidate): array => [
                        ...((array) $candidate),
                        'distance' => GeoDistance::meters((float) $osmStop->lat, (float) $osmStop->lon, (float) $candidate->lat, (float) $candidate->lon),
                    ])
                    ->filter(fn (array $candidate): bool => $candidate['distance'] <= $radius)
                    ->sortBy('distance');

                $next = null;
                foreach ($matchingStops as $candidate) {
                    $scheduleRequest = Request::create('/', 'GET', array_filter([
                        'route_id' => $candidate['route_id'],
                        'towards' => $data['towards'] ?? null,
                    ]));
                    $candidateFeed = $gtfsPublicIds[(int) $candidate['feed_id']] ?? (int) $candidate['feed_id'];
                    $schedule = $this->nextDeparture($scheduleRequest, $candidateFeed, (string) $candidate['stop_id'])->getData(true);
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
        // GTFS times are local to the agency; without a (valid) agency zone use the display zone, not UTC.
        $timezone = DB::table('transit_agencies')->where('feed_id', $feed)->value('timezone') ?: config('app.display_timezone');
        try {
            new DateTimeZone($timezone);
        } catch (\Exception) {
            $timezone = config('app.display_timezone');
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
                ->mapWithKeys(fn ($serviceId): array => [$serviceId => true]);

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
            ->get([
                't.trip_id',
                't.service_id',
                't.headsign',
                'st.arrival_seconds',
                'st.departure_seconds',
                'st.stop_sequence',
            ]);

        // Only the trips running towards the chosen end, when some do (else every direction, flagged).
        $directionMatched = null;
        $towards = $this->placeKey($data['towards'] ?? null);
        if ($towards !== '') {
            $towardsTrips = $this->tripsTowards($feed, $times, $towards);
            $directionMatched = $towardsTrips !== [];
            if ($directionMatched) {
                $times = $times->filter(fn ($time): bool => isset($towardsTrips[$time->trip_id]))->values();
            }
        }

        $timesByTrip = $times->groupBy('trip_id');
        $timepointsByTrip = collect();
        if ($times->contains(fn ($time): bool => $time->arrival_seconds === null && $time->departure_seconds === null)) {
            $timepointsByTrip = DB::table('transit_stop_times')
                ->where('feed_id', $feed)
                ->whereIn('trip_id', $times->pluck('trip_id')->unique()->values())
                ->where(fn ($query) => $query->whereNotNull('arrival_seconds')->orWhereNotNull('departure_seconds'))
                ->orderBy('trip_id')
                ->orderBy('stop_sequence')
                ->get(['trip_id', 'stop_sequence', 'arrival_seconds', 'departure_seconds'])
                ->groupBy('trip_id');
        }
        $interpolatedSeconds = [];
        foreach ($times as $time) {
            if ($time->arrival_seconds !== null || $time->departure_seconds !== null) {
                continue;
            }

            $tripTimepoints = $timepointsByTrip->get($time->trip_id, collect());
            $before = $tripTimepoints->last(fn ($point): bool => (int) $point->stop_sequence < (int) $time->stop_sequence);
            $after = $tripTimepoints->first(fn ($point): bool => (int) $point->stop_sequence > (int) $time->stop_sequence);
            $beforeSeconds = $before->departure_seconds ?? $before->arrival_seconds ?? null;
            $afterSeconds = $after->arrival_seconds ?? $after->departure_seconds ?? null;
            $sequenceSpan = $after === null ? 0 : (int) $after->stop_sequence - (int) ($before->stop_sequence ?? 0);

            if ($beforeSeconds === null || $afterSeconds === null || (int) $afterSeconds <= (int) $beforeSeconds || $sequenceSpan <= 0) {
                continue;
            }

            $elapsedStops = (int) $time->stop_sequence - (int) $before->stop_sequence;
            $interpolatedSeconds[$time->trip_id . '|' . $time->stop_sequence] = [
                'seconds' => (int) round((int) $beforeSeconds + ((int) $afterSeconds - (int) $beforeSeconds) * $elapsedStops / $sequenceSpan),
                'estimated' => true,
            ];
        }

        $frequencyRows = DB::table('transit_frequencies')
            ->where('feed_id', $feed)
            ->whereIn('trip_id', $times->pluck('trip_id')->unique()->values())
            ->get();
        $frequencyTripIds = $frequencyRows->pluck('trip_id')->unique()->all();
        $originTimes = collect();
        if ($frequencyTripIds !== []) {
            $originTimes = DB::table('transit_stop_times as origin')
                ->where('origin.feed_id', $feed)
                ->whereIn('origin.trip_id', $frequencyTripIds)
                ->whereNotExists(function ($earlier): void {
                    $earlier->selectRaw('1')
                        ->from('transit_stop_times as prior')
                        ->whereColumn('prior.feed_id', 'origin.feed_id')
                        ->whereColumn('prior.trip_id', 'origin.trip_id')
                        ->whereColumn('prior.stop_sequence', '<', 'origin.stop_sequence');
                })
                ->get(['origin.trip_id', 'origin.arrival_seconds', 'origin.departure_seconds'])
                ->keyBy('trip_id');
        }

        $next = null;
        foreach ($times as $time) {
            if (in_array($time->trip_id, $frequencyTripIds, true)) {
                continue;
            }
            $seconds = $time->arrival_seconds ?? $time->departure_seconds;
            $estimated = false;
            if ($seconds === null) {
                $inferred = $interpolatedSeconds[$time->trip_id . '|' . $time->stop_sequence] ?? null;
                $seconds = $inferred['seconds'] ?? null;
                $estimated = $inferred !== null;
            }
            if ($seconds === null) {
                continue;
            }
            foreach ($activeDatesByService[$time->service_id] ?? [] as $serviceDate) {
                $departure = $serviceDate->copy()->addSeconds((int) $seconds);
                if ($departure->lt($now) || ($next !== null && ! $departure->lt($next['at']))) {
                    continue;
                }
                $next = ['at' => $departure, 'headsign' => $time->headsign, 'estimated' => $estimated, 'trip_id' => $time->trip_id];
            }
        }

        foreach ($frequencyRows as $frequency) {
            $origin = $originTimes->get($frequency->trip_id);
            $originSeconds = $origin->departure_seconds ?? $origin->arrival_seconds ?? null;
            if ($originSeconds === null || (int) $frequency->headway_secs <= 0) {
                continue;
            }

            foreach ($timesByTrip->get($frequency->trip_id, collect()) as $time) {
                $targetSeconds = $time->departure_seconds ?? $time->arrival_seconds;
                if ($targetSeconds === null) {
                    continue;
                }
                $offsetSeconds = max(0, (int) $targetSeconds - (int) $originSeconds);
                foreach ($activeDatesByService[$time->service_id] ?? [] as $serviceDate) {
                    $firstDeparture = $serviceDate->copy()->startOfDay()->addSeconds((int) $frequency->start_seconds + $offsetSeconds);
                    $headway = (int) $frequency->headway_secs;
                    $elapsed = $now->getTimestamp() - $firstDeparture->getTimestamp();
                    $iteration = max(0, (int) ceil($elapsed / $headway));
                    $tripStart = (int) $frequency->start_seconds + $iteration * $headway;
                    if ($tripStart >= (int) $frequency->end_seconds) {
                        continue;
                    }

                    $departure = $serviceDate->copy()->startOfDay()->addSeconds($tripStart + $offsetSeconds);
                    if ($departure->lt($now) || ($next !== null && ! $departure->lt($next['at']))) {
                        continue;
                    }
                    $next = [
                        'at' => $departure,
                        'headsign' => $time->headsign,
                        'estimated' => (int) $frequency->exact_times !== 1
                            || isset($interpolatedSeconds[$time->trip_id . '|' . $time->stop_sequence]),
                        'trip_id' => $time->trip_id,
                    ];
                }
            }
        }

        if ($next === null) {
            return response()->json(['departure' => null]);
        }

        [$origin, $destination] = $this->tripEnds($feed, (string) $next['trip_id']);

        return response()->json([
            'departure' => [
                'time' => $next['at']->format('H:i'),
                // Whole clock minutes between now and the shown time, both cut to the minute: two trains shown
                // at 15:58 always say the same "in N min" (and the same minute as now is "now").
                'minutes_until' => max(0, intdiv($next['at']->getTimestamp(), 60) - intdiv($now->getTimestamp(), 60)),
                'headsign' => $next['headsign'],
                'timezone' => $timezone,
                'estimated' => $next['estimated'],
                // Where the trip starts and ends, for the details card ("from X to Y").
                'origin' => $origin,
                'destination' => $destination,
                // Only when a direction was asked for: false = no trip runs towards it, this one runs elsewhere.
                ...($directionMatched === null ? [] : ['direction_matched' => $directionMatched]),
            ],
        ]);
    }

    /**
     * A place name to compare: lowercase, without diacritics, punctuation or repeated spaces.
     */
    private function placeKey(?string $name): string
    {
        $ascii = Str::lower(Str::ascii((string) $name));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    private function samePlace(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b
            || (strlen($b) >= 3 && str_contains($a, $b))
            || (strlen($a) >= 3 && str_contains($b, $a));
    }

    /**
     * The trips (of the given stop times) that run towards the place: their headsign, else the name of
     * their last stop, matches it.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $times
     * @return array<string, true>
     */
    private function tripsTowards(int $feed, \Illuminate\Support\Collection $times, string $towards): array
    {
        $matching = [];
        $withoutHeadsign = [];
        foreach ($times->unique('trip_id') as $time) {
            $headsign = $this->placeKey($time->headsign ?? null);
            if ($headsign === '') {
                $withoutHeadsign[] = (string) $time->trip_id;
            } elseif ($this->samePlace($headsign, $towards)) {
                $matching[(string) $time->trip_id] = true;
            }
        }

        foreach (array_chunk($withoutHeadsign, 500) as $tripIds) {
            $lastStops = DB::table('transit_stop_times as st')
                ->join('transit_stops as s', fn ($join) => $join->on('s.feed_id', '=', 'st.feed_id')->on('s.stop_id', '=', 'st.stop_id'))
                ->where('st.feed_id', $feed)
                ->whereIn('st.trip_id', $tripIds)
                ->whereRaw('st.stop_sequence = (SELECT MAX(x.stop_sequence) FROM transit_stop_times x WHERE x.feed_id = st.feed_id AND x.trip_id = st.trip_id)')
                ->get(['st.trip_id', 's.name']);
            foreach ($lastStops as $lastStop) {
                if ($this->samePlace($this->placeKey($lastStop->name), $towards)) {
                    $matching[(string) $lastStop->trip_id] = true;
                }
            }
        }

        return $matching;
    }

    /**
     * Names of the first and last stop of a trip (null when unknown).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function tripEnds(int $feed, string $tripId): array
    {
        $ends = fn (string $direction): ?string => DB::table('transit_stop_times as st')
            ->join('transit_stops as s', fn ($join) => $join->on('s.feed_id', '=', 'st.feed_id')->on('s.stop_id', '=', 'st.stop_id'))
            ->where('st.feed_id', $feed)
            ->where('st.trip_id', $tripId)
            ->orderBy('st.stop_sequence', $direction)
            ->value('s.name');

        return [$ends('asc'), $ends('desc')];
    }

    public function routeShape(Request $request, int $feed, string $route): JsonResponse
    {
        $feed = $this->feedData->dataId($feed);
        $tripShapes = DB::table('transit_trips as t')
            ->where('t.feed_id', $feed)
            ->where('t.route_id', $route)
            ->whereNotNull('t.shape_id');

        $selectedStop = $request->query('stop_id');
        if (is_string($selectedStop) && $selectedStop !== '') {
            $stopIds = [$selectedStop];
            $stop = DB::table('transit_stops')
                ->where('feed_id', $feed)
                ->where('stop_id', $selectedStop)
                ->first(['location_type']);

            if ($stop && (int) $stop->location_type === 1) {
                $platformIds = DB::table('transit_stops')
                    ->where('feed_id', $feed)
                    ->where('parent_station', $selectedStop)
                    ->where('location_type', 0)
                    ->pluck('stop_id')
                    ->all();
                if ($platformIds !== []) $stopIds = $platformIds;
            }

            $tripShapes->join('transit_stop_times as st', fn ($join) => $join
                ->on('st.feed_id', '=', 't.feed_id')
                ->on('st.trip_id', '=', 't.trip_id'))
                ->whereIn('st.stop_id', $stopIds);
        }

        $shapeIds = $tripShapes
            ->select('t.direction_id', 't.shape_id', DB::raw('count(*) as trips'))
            ->groupBy('t.direction_id', 't.shape_id')
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
