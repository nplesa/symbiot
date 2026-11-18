<?php

namespace App\Services;

use App\Services\Poi\OsmAerodromes;
use App\Support\GeoCacheKey;
use App\Support\GeoDistance;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Tells which subcategories of every POI category actually have places around a location. */
class PoiSubcategoryAvailability
{
    public function __construct(
        private PoiCatalog $catalog,
        private FuelBrandAvailability $fuelBrands,
        private OsmAerodromes $aerodromes,
    ) {}

    /**
     * Both phases combined.
     *
     * @return array<string, list<string>> Subcategory ids per category. Categories whose availability is unknown are omitted.
     */
    public function around(float $lat, float $lon, int $radius): array
    {
        $result = $this->fast($lat, $lon, $radius)['subcategories'];
        foreach ($this->details($lat, $lon, $radius) as $type => $ids) {
            $result[$type] = array_values(array_unique([...($result[$type] ?? []), ...$ids]));
        }

        return $result;
    }

    /**
     * Phase 1, what answers quickly: one Geoapify existence check per category (limit 1), GTFS and the
     * local OSM extract. "pending" lists what phase 2 (details()) still completes, unknown until then:
     * the subcategories of the dense categories (restaurants, parking… need up to 500 places, which took
     * Geoapify 3-8 s), the OSM-only categories counted on Overpass, and the airport subcategories of the
     * OSM aerodromes (their ids are added to the ones given here).
     *
     * @return array{subcategories: array<string, list<string>>, pending: list<string>}
     */
    public function fast(float $lat, float $lon, int $radius): array
    {
        $key = GeoCacheKey::around('poi_subcategories:fast:v1', $lat, $lon, $radius);

        return Cache::remember($key, now()->addHours(6), fn (): array => $this->compute($lat, $lon, $radius));
    }

    /**
     * Phase 2, asked in the background: the pending categories of fast(). Categories whose source did
     * not answer are omitted (unknown); such an incomplete answer is kept for two minutes only.
     *
     * @return array<string, list<string>>
     */
    public function details(float $lat, float $lon, int $radius): array
    {
        $key = GeoCacheKey::around('poi_subcategories:details:v1', $lat, $lon, $radius);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $pending = $this->fast($lat, $lon, $radius)['pending'];
        $detailTypes = array_values(array_filter($pending, fn (string $type): bool => $this->detailedLater($type)));
        $overpassTypes = array_intersect_key($this->overpassTypes($lat, $lon, $radius), array_flip($pending));
        $wantsAerodromes = in_array('airport', $pending, true);
        [$found, $counts, $aerodromes] = $this->remoteAvailability(
            $detailTypes, [], $overpassTypes, $lat, $lon, $radius, true, self::DETAILS_GEOAPIFY_TIMEOUT_SECONDS
        );

        $result = [];
        foreach ($found as $type => $categories) {
            $result[$type] = $this->detailIds($type, $categories, $lat, $lon, $radius);
        }
        foreach ($counts as $type => $count) {
            $result[$type] = $count > 0 ? [$overpassTypes[$type]['id']] : [];
        }
        if ($wantsAerodromes && $aerodromes !== null) {
            $result['airport'] = $this->airportIds($aerodromes);
        }

        $complete = count($found) === count($detailTypes)
            && count($counts) === count($overpassTypes)
            && (! $wantsAerodromes || $aerodromes !== null);
        Cache::put($key, $result, $complete ? now()->addHours(6) : now()->addMinutes(2));

        return $result;
    }

    /** Categories detailed right away although they have several subcategories: few places, a quick answer. */
    private const IMMEDIATE_DETAIL_TYPES = ['airport'];

    /** Geoapify time limit of the background phase: up to 500 parking places of a capital took over 8 s. */
    public const DETAILS_GEOAPIFY_TIMEOUT_SECONDS = 15;

    /** A category with several Geoapify subcategories, detailed in phase 2. */
    private function detailedLater(string $type): bool
    {
        if (in_array($type, self::IMMEDIATE_DETAIL_TYPES, true) || $type === 'fuel' || ! isset($this->catalog->categories()[$type])) {
            return false;
        }
        $subcategories = $this->catalog->subcategories($type);

        return count($subcategories) > 1 && collect($subcategories)->contains(fn (array $s): bool => $s['geoapify'] !== []);
    }

    /**
     * Subcategory ids of a category with several subcategories, from the Geoapify categories found
     * around and the local OSM extract.
     *
     * @param  list<string>  $found
     * @return list<string>
     */
    private function detailIds(string $type, array $found, float $lat, float $lon, int $radius): array
    {
        $subcategories = $this->catalog->subcategories($type);
        $base = $this->osmAvailability($type, $subcategories, $lat, $lon, $radius);
        if ($base === null && $type === 'cafe' && $this->hasNearbyOsmPoints('cafe', $lat, $lon, $radius)) {
            $base = ['cafe'];
        }
        $matched = $this->matchGeoapify($subcategories, $found);
        if ($type === 'cafe' && $matched === [] && $this->hasGeoapifyRootCategory($type, $found)) {
            $matched[] = 'cafe';
        }

        return array_values(array_unique(array_merge($base ?? $this->unconditionalIds($subcategories), $matched)));
    }
    /**
     * Single-subcategory categories known only from OSM, with no local extract around: counted on Overpass.
     *
     * @return array<string, array{id: string, osm: list<array<string, ?string>>}>
     */
    private function overpassTypes(float $lat, float $lon, int $radius): array
    {
        $types = [];
        foreach (array_keys($this->catalog->categories()) as $type) {
            $subcategories = $this->catalog->subcategories($type);
            if (count($subcategories) === 1 && str_ends_with($subcategories[0]['id'], ':all')
                && $subcategories[0]['geoapify'] === [] && $subcategories[0]['osm'] !== []
                && $this->osmAvailability($type, $subcategories, $lat, $lon, $radius) === null) {
                $types[$type] = $subcategories[0];
            }
        }

        return $types;
    }

    /**
     * Airport subcategory ids of OSM aerodromes.
     *
     * @param  list<array{subcategory: string}>  $aerodromes
     * @return list<string>
     */
    private function airportIds(array $aerodromes): array
    {
        $kinds = array_column($aerodromes, 'subcategory');

        return collect($this->catalog->subcategories('airport'))
            ->filter(fn (array $s): bool => array_intersect((array) $s['geoapify'], $kinds) !== [])
            ->pluck('id')
            ->values()
            ->all();
    }

    /** @return array{subcategories: array<string, list<string>>, pending: list<string>} */
    private function compute(float $lat, float $lon, int $radius): array
    {
        $result = [];
        $geoapifyTypes = [];
        // Categories with one "…:all" subcategory: only whether anything exists nearby matters.
        $singleTypes = [];
        $hasNearbyCafes = $this->hasNearbyOsmPoints('cafe', $lat, $lon, $radius);
        $hasNearbySubway = $this->hasNearbyOsmPoints('subway', $lat, $lon, $radius)
            || $this->hasNearbyGtfsSubwayStops($lat, $lon, $radius);

        if ($hasNearbyCafes) {
            $result['cafe'] = ['cafe'];
        }
        if ($hasNearbySubway) {
            $result['subway'] = [$this->catalog->subcategories('subway')[0]['id']];
        }

        foreach (array_keys($this->catalog->categories()) as $type) {
            $subcategories = $this->catalog->subcategories($type);
            if (count($subcategories) === 1 && str_ends_with($subcategories[0]['id'], ':all')) {
                $singleTypes[$type] = $subcategories;

                continue;
            }

            if ($type === 'fuel') {
                $brands = $this->fuelBrands->around($lat, $lon, $radius);
                if ($brands !== null) {
                    $result[$type] = array_map(fn (string $id): string => 'subcategory:fuel:' . $id, $brands);
                }

                continue;
            }

            if (collect($subcategories)->contains(fn (array $s): bool => $s['geoapify'] !== [])) {
                $geoapifyTypes[] = $type;
            }

            $osm = $this->osmAvailability($type, $subcategories, $lat, $lon, $radius);
            if ($osm !== null) {
                $result[$type] = $osm;
            }
        }

        // Geoapify categories: one existence check each (limit 1). OSM-only ones: the local OSM extract
        // (Romania); outside it, one combined Overpass count query.
        $existenceTypes = [];
        $overpassTypes = [];
        foreach ($singleTypes as $type => $subcategories) {
            if ($subcategories[0]['geoapify'] !== []) {
                $existenceTypes[] = $type;
            } elseif (($osm = $this->osmAvailability($type, $subcategories, $lat, $lon, $radius)) !== null) {
                $result[$type] = $osm;
            } else {
                $overpassTypes[$type] = $subcategories[0];
            }
        }
        // Only Geoapify existence checks here; the rest comes in phase 2 (details()).
        $detailLater = array_values(array_filter($geoapifyTypes, fn (string $type): bool => $this->detailedLater($type)));
        [$geoapifyFound, , $aerodromes] = $this->remoteAvailability(
            [...$geoapifyTypes, ...$existenceTypes], [...$existenceTypes, ...$detailLater], [], $lat, $lon, $radius, false
        );
        $pending = array_keys(array_filter($overpassTypes, fn (array $subcategory): bool => $subcategory['osm'] !== []));

        foreach ($detailLater as $type) {
            // Places exist (or Geoapify did not answer): which subcategories is left to phase 2.
            if (($geoapifyFound[$type] ?? null) !== []) {
                unset($result[$type], $geoapifyFound[$type]);
                $pending[] = $type;
            }
        }

        foreach ($geoapifyFound as $type => $found) {
            $subcategories = $this->catalog->subcategories($type);
            $matched = $this->matchGeoapify($subcategories, $found);
            if ($matched === [] && isset($singleTypes[$type]) && $found !== []) {
                $matched[] = $subcategories[0]['id'];
            }
            if ($type === 'cafe' && $matched === [] && $this->hasGeoapifyRootCategory($type, $found)) {
                $matched[] = 'cafe';
            } elseif ($type === 'subway' && $matched === [] && $this->hasGeoapifyRootCategory($type, $found)) {
                $matched[] = $subcategories[0]['id'];
            }
            if ($type === 'subway' && $hasNearbySubway) {
                $matched = array_merge($matched, array_column($subcategories, 'id'));
            }
            $result[$type] = array_values(array_unique(array_merge(
                $result[$type] ?? $this->unconditionalIds($subcategories),
                $matched,
            )));
        }

        // Airport subcategories also from the OSM aerodromes (military, gliding, private… airfields
        // that Geoapify often does not have).
        if (isset($this->catalog->categories()['airport'])) {
            if ($aerodromes !== null) {
                $result['airport'] = array_values(array_unique([...($result['airport'] ?? []), ...$this->airportIds($aerodromes)]));
            } elseif ($this->aerodromes->needsFetch($lat, $lon)) {
                $pending[] = 'airport';
            }
        }

        foreach (['bus', 'train'] as $type) {
            if (($result[$type] ?? null) === []
                && ($this->hasNearbyOsmPoints($type, $lat, $lon, $radius)
                    || ($type === 'bus' && $this->hasNearbyGtfsStops($lat, $lon, $radius)))) {
                $result[$type] = [$singleTypes[$type][0]['id']];
            }
        }

        return ['subcategories' => $result, 'pending' => $pending];
    }

    /**
     * @param  list<array<string, mixed>>  $subcategories
     * @return list<string>
     */
    private function unconditionalIds(array $subcategories): array
    {
        return collect($subcategories)
            ->filter(fn (array $s): bool => $s['geoapify'] === [])
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $subcategories
     * @param  list<string>  $found
     * @return list<string>
     */
    private function matchGeoapify(array $subcategories, array $found): array
    {
        $matched = [];
        foreach ($subcategories as $subcategory) {
            foreach ($subcategory['geoapify'] as $category) {
                foreach ($found as $name) {
                    if ($name === $category || str_starts_with($name, $category . '.')) {
                        $matched[] = $subcategory['id'];
                        break 2;
                    }
                }
            }
        }

        return $matched;
    }

    /** @param list<string> $found */
    private function hasGeoapifyRootCategory(string $type, array $found): bool
    {
        foreach ($this->catalog->categories()[$type]['geoapify'] as $category) {
            if (in_array($category, $found, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasNearbyOsmPoints(string $type, float $lat, float $lon, int $radius): bool
    {
        $latPad = $radius / 111320;
        $lonPad = $radius / (111320 * max(0.01, cos(deg2rad($lat))));

        return DB::table('transport_points as p')
            ->where('p.type', $type)
            ->whereBetween('p.lat', [$lat - $latPad, $lat + $latPad])
            ->whereBetween('p.lon', [$lon - $lonPad, $lon + $lonPad])
            ->whereRaw($this->withinRadiusSql('p'), [$lat, $lat, $lon, $radius])
            ->exists();
    }

    /** Total time for the Overpass requests of phase 2, all servers included (a busy server must not hold it up). */
    public const OVERPASS_BUDGET_SECONDS = 3;

    /**
     * Geoapify existence checks and the Overpass count in one parallel batch. Overpass starts with the
     * first server; when it fails, the next ones are tried only within OVERPASS_BUDGET_SECONDS.
     *
     * @param  list<string>  $geoapifyTypes
     * @param  list<string>  $existenceOnly
     * @param  array<string, array{id: string, osm: list<array<string, ?string>>}>  $overpassTypes
     * @return array{0: array<string, list<string>>, 1: array<string, int>, 2: ?list<array{id: string, name: ?string, lat: float, lon: float, subcategory: string, tags: array<string, string>}>}
     */
    private function remoteAvailability(array $geoapifyTypes, array $existenceOnly, array $overpassTypes, float $lat, float $lon, int $radius, bool $withOsm, int $geoapifyTimeout = 8): array
    {
        $geoapifyTypes = config('services.geoapify.key') ? $geoapifyTypes : [];
        $overpassTypes = array_filter($overpassTypes, fn (array $subcategory): bool => $subcategory['osm'] !== []);
        $query = $overpassTypes !== [] ? $this->overpassQuery($overpassTypes, $lat, $lon, $radius) : null;
        $endpoints = array_values(array_filter((array) config('osm.overpass_endpoints'), 'is_string'));
        if ($query === null) {
            $endpoints = [];
        }
        $hasOverpass = array_filter((array) config('osm.overpass_endpoints'), 'is_string') !== [];
        $fetchAerodromes = $withOsm && $hasOverpass && isset($this->catalog->categories()['airport']) && $this->aerodromes->needsFetch($lat, $lon);
        if ($geoapifyTypes === [] && $endpoints === [] && ! $fetchAerodromes) {
            return [[], [], $this->aerodromes->cached($lat, $lon, $radius)];
        }

        $started = microtime(true);
        $responses = Http::pool(function (Pool $pool) use ($geoapifyTypes, $existenceOnly, $endpoints, $query, $lat, $lon, $radius, $fetchAerodromes, $geoapifyTimeout): array {
            $requests = [];
            foreach ($geoapifyTypes as $type) {
                $requests[] = $pool->as('geoapify:' . $type)->connectTimeout(3)->timeout($geoapifyTimeout)->acceptJson()->get('https://api.geoapify.com/v2/places', [
                    'categories' => implode(',', $this->catalog->geoapifyQuery($type)),
                    'filter' => sprintf('circle:%F,%F,%d', $lon, $lat, $radius),
                    'limit' => in_array($type, $existenceOnly, true) ? 1 : 500,
                    'apiKey' => config('services.geoapify.key'),
                ]);
            }
            if ($endpoints !== []) {
                $requests[] = $this->overpassRequest($pool->as('overpass'), self::OVERPASS_BUDGET_SECONDS)->post($endpoints[0], ['data' => $query]);
            }
            if ($fetchAerodromes) {
                $requests[] = $this->aerodromes->overpassRequest($pool->as('aerodromes'), $lat, $lon, self::OVERPASS_BUDGET_SECONDS);
            }

            return $requests;
        });

        $found = [];
        foreach ($geoapifyTypes as $type) {
            $response = $responses['geoapify:' . $type] ?? null;
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }

            $features = $response->json('features');
            $found[$type] = collect(is_array($features) ? $features : [])
                ->flatMap(fn (array $feature): array => $feature['properties']['categories'] ?? [])
                ->unique()
                ->values()
                ->all();
        }

        // Aerodromes first, with their own few seconds for the quick Nominatim fallback (the parallel batch
        // may already have used the Overpass budget); the other Overpass servers get what is left of it.
        $aerodromes = $fetchAerodromes
            ? $this->aerodromes->complete($responses['aerodromes'] ?? null, $lat, $lon, $radius, microtime(true), OsmAerodromes::FALLBACK_SECONDS)
            : $this->aerodromes->cached($lat, $lon, $radius);

        $counts = $endpoints !== [] ? $this->overpassCountsFrom($responses['overpass'] ?? null, $overpassTypes) : null;
        foreach (array_slice($endpoints, 1) as $endpoint) {
            $left = (int) floor(self::OVERPASS_BUDGET_SECONDS - (microtime(true) - $started));
            if ($counts !== null || $left < 2) {
                break;
            }
            try {
                $counts = $this->overpassCountsFrom($this->overpassRequest(Http::acceptJson(), $left)->post($endpoint, ['data' => $query]), $overpassTypes);
            } catch (\Throwable) {
                // Try the next server while time is left.
            }
        }

        return [$found, $counts ?? [], $aerodromes];
    }

    private function overpassRequest(PendingRequest $request, int $timeout): PendingRequest
    {
        return $request->withHeaders(['User-Agent' => 'Symbiot/1.0 POI availability'])
            ->connectTimeout(min(3, $timeout))
            ->timeout($timeout)
            ->acceptJson()
            ->asForm();
    }

    /**
     * Counts per category from an Overpass "out count" answer (one per category, in order),
     * or null when the server failed: the categories then stay unknown, so they are not hidden.
     *
     * @param  array<string, array{id: string, osm: list<array<string, ?string>>}>  $types
     * @return ?array<string, int>
     */
    private function overpassCountsFrom(mixed $response, array $types): ?array
    {
        if (! $response instanceof Response || ! $response->successful() || ! is_array($elements = $response->json('elements'))) {
            return null;
        }

        $counts = array_values(array_filter($elements, fn ($element): bool => is_array($element) && ($element['type'] ?? null) === 'count'));
        if (count($counts) !== count($types)) {
            return null;
        }

        return array_combine(
            array_keys($types),
            array_map(fn (array $count): int => (int) ($count['tags']['total'] ?? 0), $counts)
        );
    }

    /**
     * One Overpass query counting the OSM objects of each category within the radius.
     *
     * @param  array<string, array{id: string, osm: list<array<string, ?string>>}>  $types
     */
    private function overpassQuery(array $types, float $lat, float $lon, int $radius): string
    {
        $around = sprintf('(around:%d,%F,%F)', $radius, $lat, $lon);
        $query = '[out:json][timeout:' . self::OVERPASS_BUDGET_SECONDS . '];';
        foreach ($types as $subcategory) {
            $query .= '(';
            foreach ($subcategory['osm'] as $selector) {
                $filters = '';
                foreach ($selector as $key => $values) {
                    $filters .= $values === null
                        ? '["' . $key . '"]'
                        : (str_contains($values, '|') ? '["' . $key . '"~"^(' . $values . ')$"]' : '["' . $key . '"="' . $values . '"]');
                }
                $query .= 'nwr' . $filters . $around . ';';
            }
            $query .= ');out count;';
        }

        return $query;
    }

    private function hasNearbyGtfsStops(float $lat, float $lon, int $radius): bool
    {
        $latPad = $radius / 111320;
        $lonPad = $radius / (111320 * max(0.01, cos(deg2rad($lat))));

        return DB::table('transit_stops as s')
            // Rows are served through the public feed's data_feed_id (GTFS imports switch to new data that way).
            ->join('transit_feeds as f', fn ($join) => $join->on(DB::raw('COALESCE(f.data_feed_id, f.id)'), '=', 's.feed_id'))
            ->whereNull('f.shadow_of')
            ->where('f.active', true)
            ->whereNotNull('f.static_hash')
            ->whereBetween('s.lat', [$lat - $latPad, $lat + $latPad])
            ->whereBetween('s.lon', [$lon - $lonPad, $lon + $lonPad])
            ->whereRaw($this->withinRadiusSql('s'), [$lat, $lat, $lon, $radius])
            ->exists();
    }

    /**
     * Whether a GTFS metro station (route_type 1 or the urban railway types 400-499) lies within the radius.
     * It starts from the few metro lines instead of every stop in the circle: from each stop, looking
     * through all its stop_times for a metro line took minutes in big cities (206 s in Bucharest).
     */
    private function hasNearbyGtfsSubwayStops(float $lat, float $lon, int $radius): bool
    {
        $latPad = $radius / 111320;
        $lonPad = $radius / (111320 * max(0.01, cos(deg2rad($lat))));
        $feeds = DB::table('transit_feeds')
            ->whereNull('shadow_of')
            ->where('active', true)
            ->whereNotNull('static_hash')
            ->where(fn ($query) => $query->whereNull('min_lat')->orWhere(fn ($area) => $area
                ->where('min_lat', '<=', $lat + $latPad)->where('max_lat', '>=', $lat - $latPad)
                ->where('min_lon', '<=', $lon + $lonPad)->where('max_lon', '>=', $lon - $lonPad)))
            ->get(['id', 'data_feed_id']);

        foreach ($feeds as $feed) {
            foreach ($this->gtfsSubwayStops((int) ($feed->data_feed_id ?? $feed->id)) as [$stopLat, $stopLon]) {
                if (GeoDistance::meters($lat, $lon, $stopLat, $stopLon) <= $radius) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Coordinates of the metro stations of one GTFS data set, from one trip per line variant
     * (route, direction, shape). Cached for a day; a re-import gets a new data id, so a new entry.
     *
     * @return list<array{float, float}>
     */
    private function gtfsSubwayStops(int $dataFeedId): array
    {
        return Cache::remember('gtfs_subway_stops:v1:' . $dataFeedId, now()->addDay(), function () use ($dataFeedId): array {
            $trips = DB::table('transit_trips as t')
                ->join('transit_routes as r', fn ($join) => $join->on('r.feed_id', '=', 't.feed_id')->on('r.route_id', '=', 't.route_id'))
                ->where('t.feed_id', $dataFeedId)
                ->where(fn ($query) => $query->where('r.route_type', 1)->orWhereBetween('r.route_type', [400, 499]))
                ->groupBy('t.route_id', 't.direction_id', 't.shape_id')
                ->selectRaw('MIN(t.trip_id) as trip_id')
                ->pluck('trip_id')
                ->all();
            if ($trips === []) {
                return [];
            }

            return DB::table('transit_stop_times as st')
                ->join('transit_stops as s', fn ($join) => $join->on('s.feed_id', '=', 'st.feed_id')->on('s.stop_id', '=', 'st.stop_id'))
                ->where('st.feed_id', $dataFeedId)
                ->whereIn('st.trip_id', $trips)
                ->distinct()
                ->get(['s.lat', 's.lon'])
                ->map(fn ($stop): array => [(float) $stop->lat, (float) $stop->lon])
                ->all();
        });
    }

    private function withinRadiusSql(string $alias): string
    {
        return "6371000 * 2 * ASIN(SQRT(LEAST(1, POWER(SIN(RADIANS(? - {$alias}.lat) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS({$alias}.lat)) * POWER(SIN(RADIANS(? - {$alias}.lon) / 2), 2)))) <= ?";
    }

    /**
     * @param  list<array<string, mixed>>  $subcategories
     * @return ?list<string> Null when there is no local data to decide from.
     */
    private function osmAvailability(string $type, array $subcategories, float $lat, float $lon, int $radius): ?array
    {
        $osmOnly = array_filter($subcategories, fn (array $s): bool => $s['geoapify'] === []);
        if ($osmOnly === [] || count($osmOnly) !== count($subcategories)) {
            return null;
        }

        $latPad = $radius / 111320;
        $lonPad = $radius / (111320 * max(0.01, cos(deg2rad($lat))));
        $counts = DB::table('transport_points')
            ->whereBetween('lat', [$lat - $latPad, $lat + $latPad])
            ->whereBetween('lon', [$lon - $lonPad, $lon + $lonPad])
            ->select('type', DB::raw('count(*) as total'))
            ->groupBy('type')
            ->pluck('total', 'type');

        if ($counts->isEmpty()) {
            return null;
        }

        return collect($subcategories)
            ->filter(fn (array $s): bool => $s['osm'] === [] || $counts->has($s['type'] ?? $type))
            ->pluck('id')
            ->values()
            ->all();
    }
}
