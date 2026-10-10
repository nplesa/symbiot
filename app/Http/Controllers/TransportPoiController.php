<?php

namespace App\Http\Controllers;

use App\Services\PoiCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TransportPoiController extends Controller
{
    public function nearbyForType(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['bus', 'train', 'subway', 'airport'], true), 404);
        $request->query->set('types', $type);

        return $this->nearby($request);
    }

    public function nearbyForCategory(Request $request, string $category): JsonResponse
    {
        $catalog = app(PoiCatalog::class);
        abort_unless(isset($catalog->categories()[$category]), 404);

        if (! $request->has('types')) {
            $request->query->set('types', $category);

            return $this->nearby($request);
        }

        $filters = collect(explode(',', (string) $request->query('types')))
            ->map(fn (string $type): string => trim($type))
            ->filter(fn (string $type): bool => ($catalog->filters()[$type]['type'] ?? null) === $category)
            ->unique()
            ->values();

        if ($filters->isEmpty()) {
            return response()->json([]);
        }

        $request->query->set('types', $filters->implode(','));

        return $this->nearby($request);
    }

    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:100|max:35000',
            'types' => 'nullable|string',
        ]);

        $lat = (float) $validated['lat'];
        $lon = (float) $validated['lon'];
        $radius = (int) ($validated['radius'] ?? 5000);
        $includeTransitRoutes = $request->boolean('include_routes', true);

        $catalog = app(PoiCatalog::class);
        $filters = $catalog->filters();
        $selectedFilters = $request->has('types')
            ? collect(explode(',', (string) ($validated['types'] ?? '')))
                ->map(fn ($type) => trim($type))
                ->filter(fn ($type) => isset($filters[$type]))
                ->unique()
                ->values()
                ->all()
            : $catalog->enabledTypes();

        if ($selectedFilters === []) {
            return response()->json([]);
        }

        $selected = collect($selectedFilters)->map(fn (string $filter) => $filters[$filter]);
        $poiTypes = $selected->pluck('type')->unique()->values()->all();
        $categories = $this->buildCategories($selected->all());
        $osmSelectors = $selected
            ->filter(fn (array $filter) => $filter['geoapify'] === [])
            ->flatMap(fn (array $filter) => $filter['osm'])
            ->unique()
            ->values()
            ->all();
        $cacheKey = sprintf(
            'transport_poi:v15:%s:%s:%s:%s:%s',
            round($lat, 4),
            round($lon, 4),
            $radius,
            md5(implode(',', $selectedFilters)),
            $includeTransitRoutes ? 'routes' : 'no-routes'
        );
        $transitTypes = array_values(array_intersect($poiTypes, ['bus', 'subway', 'train', 'airport']));

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($lat, $lon, $radius, $categories, $osmSelectors, $selected, $transitTypes, $includeTransitRoutes) {
                $features = $categories !== ''
                    ? $this->fetchFromGeoapify($lat, $lon, $radius, $categories)
                    : [];
                $osmElements = $osmSelectors !== []
                    ? $this->fetchFromOpenStreetMap($lat, $lon, $radius, $osmSelectors)
                    : [];
                $missingTransitTypes = array_values(array_filter(
                    $transitTypes,
                    fn (string $type): bool => ! collect($features)->contains(
                        fn (array $feature): bool => $this->detectType($feature['properties']['categories'] ?? []) === $type
                    )
                ));
                if (! $includeTransitRoutes) {
                    $missingTransitTypes = [];
                }
                $transitFallbackSelectors = $selected
                    ->filter(fn (array $filter) => in_array($filter['type'], $missingTransitTypes, true))
                    ->flatMap(fn (array $filter) => $filter['osm'])
                    ->unique()
                    ->values()
                    ->all();
                $transitFallbackElements = [];
                if ($transitFallbackSelectors !== []) {
                    $transitFallbackElements = $this->fetchFromOpenStreetMap(
                        $lat,
                        $lon,
                        $radius,
                        $transitFallbackSelectors
                    );
                }
                $transitRouteData = $transitTypes !== [] && $includeTransitRoutes
                    ? $this->fetchTransitRouteData(
                        $lat,
                        $lon,
                        $radius,
                        $transitTypes,
                        $features,
                        $transitFallbackElements
                    )
                    : ['stops' => [], 'routes' => [], 'available' => ! $includeTransitRoutes];

                return [
                    'features' => $features,
                    'osm_elements' => array_merge($osmElements, $transitFallbackElements),
                    'transit_stops' => $transitRouteData['stops'],
                    'transit_routes' => $transitRouteData['routes'],
                    'transit_routes_available' => $transitRouteData['available'],
                ];
            });
            if ($includeTransitRoutes && $transitTypes !== [] && ! ($data['transit_routes_available'] ?? false)) {
                Cache::forget($cacheKey);
            }

            $result = array_merge(
                $this->transform(
                    $data['features'] ?? [],
                    $lat,
                    $lon,
                    $data['transit_stops'] ?? [],
                    $data['transit_routes'] ?? [],
                    $data['transit_routes_available'] ?? false
                ),
                $this->transformOsm(
                    $data['osm_elements'] ?? [],
                    $lat,
                    $lon,
                    $radius,
                    $poiTypes,
                    $data['transit_stops'] ?? [],
                    $data['transit_routes'] ?? [],
                    $data['transit_routes_available'] ?? false
                ),
            );

            return response()->json($result);

        } catch (\Throwable $e) {
            Log::error('POI category lookup failed', [
                'categories' => $poiTypes,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function wazeTrafficAlerts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:100|max:35000',
        ]);
        $lat = (float) $validated['lat'];
        $lon = (float) $validated['lon'];
        $radius = (int) ($validated['radius'] ?? 5000);
        $cacheKey = sprintf(
            'waze_police_alerts:v1:%s:%s:%s',
            round($lat, 4),
            round($lon, 4),
            $radius
        );

        try {
            $alerts = Cache::remember(
                $cacheKey,
                now()->addMinutes(10),
                fn (): array => $this->fetchWazePoliceAlerts($lat, $lon, $radius)
            );

            return response()->json($this->transformWazePoliceAlerts($alerts, $lat, $lon, $radius));
        } catch (\Throwable $e) {
            Log::error('Waze traffic alert lookup failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function routeGeometry(int $relationId): JsonResponse
    {
        $cacheKey = 'transit_route_geometry:v1:' . $relationId;

        try {
            $route = Cache::remember($cacheKey, now()->addHours(6), function () use ($relationId): array {
                $response = Http::withHeaders([
                    'User-Agent' => 'Symbiot/1.0 transit route geometry',
                ])->timeout(35)
                    ->retry(1, 300)
                    ->acceptJson()
                    ->get("https://api.openstreetmap.org/api/0.6/relation/{$relationId}/full.json");

                if (! $response->successful()) {
                    throw new \RuntimeException('OpenStreetMap API returned HTTP ' . $response->status() . '.');
                }

                $payload = $response->json();
                $elements = $payload['elements'] ?? [];
                $relation = collect($elements)
                    ->first(fn (array $element): bool => ($element['type'] ?? null) === 'relation'
                        && (int) ($element['id'] ?? 0) === $relationId);
                if ($relation === null) {
                    throw new \RuntimeException('OpenStreetMap route relation was not found.');
                }

                $nodes = [];
                $ways = [];
                foreach ($elements as $element) {
                    if (($element['type'] ?? null) === 'node'
                        && isset($element['id'], $element['lat'], $element['lon'])) {
                        $nodes[$element['id']] = [(float) $element['lon'], (float) $element['lat']];
                    } elseif (($element['type'] ?? null) === 'way' && isset($element['id'])) {
                        $ways[$element['id']] = $element;
                    }
                }

                $segments = [];
                foreach ($relation['members'] ?? [] as $member) {
                    if (($member['type'] ?? null) !== 'way') {
                        continue;
                    }

                    $way = $ways[$member['ref'] ?? null] ?? null;
                    if ($way === null) {
                        continue;
                    }

                    $coordinates = [];
                    foreach ($way['nodes'] ?? [] as $nodeId) {
                        if (isset($nodes[$nodeId])) {
                            $coordinates[] = $nodes[$nodeId];
                        }
                    }
                    if (count($coordinates) >= 2) {
                        $segments[] = $coordinates;
                    }
                }

                return [
                    'segments' => $segments,
                    'updated_at' => $relation['timestamp'] ?? null,
                ];
            });

            if ($route['segments'] === []) {
                Cache::forget($cacheKey);

                return response()->json([
                    'error' => 'No route geometry is available in OpenStreetMap.',
                ], 404);
            }

            return response()->json($route);
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            Log::warning('OpenStreetMap transit route geometry lookup failed', [
                'relation_id' => $relationId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Could not load this route geometry from OpenStreetMap.',
            ], 502);
        }
    }

    /** @return list<array<string, mixed>> */
    private function fetchFromGeoapify(float $lat, float $lon, int $radius, string $categories): array
    {
        $points = $this->buildGridPoints($lat, $lon, $radius);
        $timeoutSeconds = trim($categories) === 'public_transport.train' ? 5 : 10;
        $all = [];

        foreach (array_chunk(array_filter(explode(',', $categories)), 100) as $categoryChunk) {
            $categoryList = implode(',', $categoryChunk);

            foreach ($points as [$pLat, $pLon]) {
                try {
                    $response = Http::timeout($timeoutSeconds)
                        ->retry(1, 200)
                        ->acceptJson()
                        ->get('https://api.geoapify.com/v2/places', [
                            'categories' => $categoryList,
                            'filter' => "circle:$pLon,$pLat,$radius",
                            'limit' => 500,
                            'apiKey' => config('services.geoapify.key'),
                        ]);

                    if ($response->successful()) {
                        $features = $response->json('features') ?? [];
                        $all = array_merge($all, $features);
                    }

                } catch (\Throwable $e) {
                    Log::warning('Geoapify partial failure', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $this->deduplicate($all);
    }

    /** @return list<array{float, float}> */
    private function buildGridPoints(float $lat, float $lon, int $radius): array
    {
        if ($radius <= 15000) {
            return [[$lat, $lon]];
        }

        $delta = 0.25;

        return [
            [$lat, $lon],

            [$lat + $delta, $lon],
            [$lat - $delta, $lon],
            [$lat, $lon + $delta],
            [$lat, $lon - $delta],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @return list<array<string, mixed>>
     */
    private function deduplicate(array $features): array
    {
        return collect($features)
            ->unique(fn (array $f): mixed => $f['properties']['place_id'] ?? null)
            ->values()
            ->all();
    }

    /** @param list<array{type: string, label: string, geoapify: list<string>, osm: list<array<string, ?string>>}> $filters */
    private function buildCategories(array $filters): string
    {
        $categories = collect($filters)
            ->flatMap(fn (array $filter) => $filter['geoapify'])
            ->unique()
            ->values();

        return $categories->isEmpty()
            ? ''
            : $categories->implode(',');
    }

    /**
     * @param  list<array<string, ?string>>  $selectors
     * @return list<array<string, mixed>>
     */
    private function fetchFromOpenStreetMap(float $lat, float $lon, int $radius, array $selectors): array
    {
        $latPadding = $radius / 111320;
        $lonPadding = $radius / (111320 * max(0.01, cos(deg2rad($lat))));
        $bbox = [
            max(-90, $lat - $latPadding),
            max(-180, $lon - $lonPadding),
            min(90, $lat + $latPadding),
            min(180, $lon + $lonPadding),
        ];
        $box = implode(',', $bbox);
        $queries = [];

        foreach ($selectors as $selector) {
            $filters = '';

            foreach ($selector as $key => $values) {
                $filters .= $values === null
                    ? '["' . $key . '"]'
                    : (str_contains($values, '|')
                        ? '["' . $key . '"~"^(' . $values . ')$"]'
                        : '["' . $key . '"="' . $values . '"]');
            }

            $queries[] = 'nwr' . $filters . '(' . $box . ');';
        }

        $response = Http::withHeaders([
            'User-Agent' => 'Symbiot/1.0 nearby POI map',
        ])->timeout(25)
            ->acceptJson()
            ->get('https://overpass-api.de/api/interpreter', [
                'data' => '[out:json][timeout:25];(' . implode('', array_unique($queries)) . ');out center tags;',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenStreetMap POI service returned HTTP ' . $response->status() . '.');
        }

        return $response->json('elements') ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchWazePoliceAlerts(float $lat, float $lon, int $radius): array
    {
        $baseUrl = config('services.waze.url');
        $apiKey = config('services.waze.key');
        if (! is_string($baseUrl) || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('WAZE_API_URL nu este configurat cu un URL valid.');
        }
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new \RuntimeException('WAZE_API_KEY nu este configurată. Adaugă cheia în .env și reîncarcă configurația aplicației.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'User-Agent' => 'Symbiot/1.0 nearby Waze traffic alerts',
        ])->timeout(20)
            ->acceptJson()
            ->get(rtrim($baseUrl, '/') . '/alerts-and-jams', [
                'center' => $lat . ',' . $lon,
                'radius' => round($radius / 1000, 2),
                'radius_units' => 'KM',
            ]);

        if (! $response->successful()) {
            $status = $response->status();
            $message = match ($status) {
                401, 403 => "OpenWebNinja Waze API returned HTTP {$status}. Verifică WAZE_API_KEY și confirmă că API-ul Waze este activat în contul OpenWebNinja.",
                429 => 'OpenWebNinja Waze API returned HTTP 429. Cota sau limita de cereri a fost atinsă.',
                default => "OpenWebNinja Waze API returned HTTP {$status}.",
            };

            throw new \RuntimeException($message);
        }

        $alerts = $response->json('data.alerts');
        if (! is_array($alerts)) {
            throw new \RuntimeException('OpenWebNinja Waze API returned an unexpected alerts response.');
        }

        return array_values(array_filter($alerts, static fn ($alert): bool => is_array($alert)));
    }

    /**
     * @param  list<array<string, mixed>>  $alerts
     * @return list<array<string, mixed>>
     */
    private function transformWazePoliceAlerts(array $alerts, float $userLat, float $userLon, int $radius): array
    {
        $pois = [];

        foreach ($alerts as $alert) {
            if (strtoupper((string) ($alert['type'] ?? '')) !== 'POLICE') {
                continue;
            }

            $lat = filter_var($alert['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($alert['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($lat === false || $lon === false || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                continue;
            }

            $meters = $this->distance($userLat, $userLon, (float) $lat, (float) $lon);
            if ($meters > $radius) {
                continue;
            }

            $street = trim((string) ($alert['street'] ?? ''));
            $city = trim((string) ($alert['city'] ?? ''));
            $description = trim((string) ($alert['description'] ?? ''));
            $subtype = trim(str_replace('_', ' ', (string) ($alert['subtype'] ?? '')));
            $id = trim((string) ($alert['alert_id'] ?? ''));
            $pois[] = [
                'id' => 'waze/' . ($id !== '' ? $id : sha1(json_encode([$lat, $lon, $alert['type'] ?? 'POLICE']))),
                'name' => $description !== '' ? $description : ($subtype !== '' ? ucfirst(strtolower($subtype)) : 'Filtru de poliție Waze'),
                'type' => 'police',
                'routes' => [],
                'routes_available' => true,
                'coordinates' => ['lat' => (float) $lat, 'lon' => (float) $lon],
                'distance' => [
                    'meters' => (int) round($meters),
                    'km' => round($meters / 1000, 2),
                    'formatted' => $meters < 1000
                        ? round($meters) . ' m'
                        : round($meters / 1000, 1) . ' km',
                ],
                'address' => [
                    'formatted' => implode(', ', array_filter([$street, $city])),
                    'city' => $city !== '' ? $city : null,
                    'country' => $alert['country'] ?? null,
                ],
                'details' => array_filter([
                    'provider' => 'Waze',
                    'reported_at' => $alert['publish_datetime_utc'] ?? null,
                ]),
            ];
        }

        return $pois;
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @param  list<array<string, mixed>>  $fallbackElements
     * @return array{stops: list<array<string, mixed>>, routes: list<array<string, mixed>>, available: bool}
     */
    private function fetchTransitRouteData(
        float $lat,
        float $lon,
        int $radius,
        array $types,
        array $features,
        array $fallbackElements = []
    ): array {
        $routeTypes = [];
        foreach ($types as $type) {
            $routeTypes = array_merge($routeTypes, match ($type) {
                'bus' => ['bus', 'trolleybus'],
                'subway' => ['subway'],
                'train' => ['train', 'light_rail', 'tram', 'monorail', 'funicular'],
                'airport' => ['airline'],
                default => [],
            });
        }
        $routeTypes = array_values(array_unique($routeTypes));
        if ($routeTypes === []) {
            return ['stops' => [], 'routes' => [], 'available' => true];
        }

        $routeTypeFilter = implode('|', array_map(
            static fn (string $type): string => preg_quote($type, '/'),
            $routeTypes
        ));
        $stopSelectors = $this->transitStopSelectors($routeTypes);

        $nearbyPoints = [];
        foreach ($features as $feature) {
            $properties = $feature['properties'] ?? [];
            $coordinates = $feature['geometry']['coordinates'] ?? [];
            if (in_array($this->detectType($properties['categories'] ?? []), $types, true)
                && isset($coordinates[0], $coordinates[1])) {
                $nearbyPoints[sprintf('%.5f,%.5f', (float) $coordinates[1], (float) $coordinates[0])] = true;
            }
        }
        foreach ($fallbackElements as $element) {
            if (! in_array(app(PoiCatalog::class)->osmType($element['tags'] ?? []), $types, true)) {
                continue;
            }
            $coordinates = isset($element['lat'], $element['lon'])
                ? [(float) $element['lat'], (float) $element['lon']]
                : [
                    (float) ($element['center']['lat'] ?? 0),
                    (float) ($element['center']['lon'] ?? 0),
                ];
            if ($coordinates[0] !== 0.0 || $coordinates[1] !== 0.0) {
                $nearbyPoints[sprintf('%.5f,%.5f', $coordinates[0], $coordinates[1])] = true;
            }
        }
        if ($nearbyPoints === []) {
            return ['stops' => [], 'routes' => [], 'available' => true];
        }
        $stopQueries = [];
        $searchRadius = $radius + 500;
        $searchCenter = sprintf('%.5f,%.5f', $lat, $lon);
        foreach ($stopSelectors as $selector) {
            $stopQueries[] = 'nwr(around:' . $searchRadius . ',' . $searchCenter . ')' . $selector . ';';
        }
        $query = '[out:json][timeout:20];'
            . '(' . implode('', $stopQueries) . ')->.stops;'
            . '.stops out center tags;'
            . '(rel(bn.stops)["route"~"^(' . $routeTypeFilter . ')$"];'
            . 'rel(bw.stops)["route"~"^(' . $routeTypeFilter . ')$"];)->.routes;'
            . '.routes out meta;'
            . '.routes out body;';

        $payload = null;
        $lastError = null;
        foreach ([
            'https://overpass-api.de/api/interpreter',
            'https://overpass.private.coffee/api/interpreter',
        ] as $endpoint) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Symbiot/1.0 nearby POI map',
                ])->timeout(25)
                    ->acceptJson()
                    ->asForm()
                    ->post($endpoint, [
                        'data' => $query,
                    ]);

                if (! $response->successful()) {
                    throw new \RuntimeException('OpenStreetMap transit route service returned HTTP ' . $response->status() . '.');
                }

                $candidate = $response->json();
                if (! is_array($candidate) || isset($candidate['remark'])) {
                    throw new \RuntimeException('OpenStreetMap transit route service returned an incomplete result.');
                }

                $payload = $candidate;
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        try {
            if ($payload === null) {
                throw $lastError ?? new \RuntimeException('OpenStreetMap transit route services are unavailable.');
            }

            $elements = $payload['elements'] ?? [];
            $stops = array_values(array_filter(
                $elements,
                fn (array $element): bool => ($element['type'] ?? null) !== 'relation' && isset($element['tags'])
            ));
            $routes = array_values(array_filter($elements, fn (array $element): bool => isset($element['tags']['route'])));
            $available = true;

            if (in_array('train', $types, true)) {
                $fallback = $this->fetchTrainRoutesFromOsmApi($features, $stops, $routes, $fallbackElements);
                $routes = array_merge($routes, $fallback['routes']);
                $available = $fallback['available'];
            }

            return [
                'stops' => $stops,
                'routes' => collect($routes)->unique(fn (array $route): string => 'relation/' . ($route['id'] ?? ''))->values()->all(),
                'available' => $available,
            ];
        } catch (\Throwable $e) {
            Log::warning('OpenStreetMap transit route lookup failed', [
                'message' => $e->getMessage(),
            ]);

            return ['stops' => [], 'routes' => [], 'available' => false];
        }
    }

    /**
     * @param  list<string>  $routeTypes
     * @return list<string>
     */
    private function transitStopSelectors(array $routeTypes): array
    {
        $subwayOnly = $routeTypes === ['subway'];
        $stopSelectors = $subwayOnly
            ? ['["subway"="yes"]']
            : ['["public_transport"~"^(platform|stop_position|station)$"]'];
        if (array_intersect($routeTypes, ['bus', 'trolleybus']) !== []) {
            $stopSelectors[] = '["highway"="bus_stop"]';
            $stopSelectors[] = '["amenity"="bus_station"]';
            $stopSelectors[] = '["public_transport"~"^(platform|stop_position)$"]["bus"="yes"]';
        }
        if (! $subwayOnly && in_array('subway', $routeTypes, true)) {
            $stopSelectors[] = '["railway"~"^(station|subway_entrance)$"]';
        }
        if (array_intersect($routeTypes, ['train', 'light_rail', 'tram', 'monorail', 'funicular']) !== []) {
            $stopSelectors[] = '["railway"~"^(station|halt|tram_stop|subway_entrance)$"]';
            $stopSelectors[] = '["railway"="stop"]';
        }
        if (in_array('airline', $routeTypes, true)) {
            $stopSelectors[] = '["aeroway"="aerodrome"]';
        }

        return $stopSelectors;
    }

    /**
     * Overpass can omit parent route relations for railway stop-position nodes.
     * Resolve only unmatched stops near returned Geoapify train stations.
     *
     * @param  list<array<string, mixed>>  $features
     * @param  list<array<string, mixed>>  $stops
     * @param  list<array<string, mixed>>  $routes
     * @param  list<array<string, mixed>>  $fallbackElements
     * @return array{routes: list<array<string, mixed>>, available: bool}
     */
    private function fetchTrainRoutesFromOsmApi(
        array $features,
        array $stops,
        array $routes,
        array $fallbackElements = []
    ): array {
        $routesByStop = $this->routesByTransitStop($stops, $routes);
        $unmatchedStopIds = [];

        foreach ($features as $feature) {
            $properties = $feature['properties'] ?? [];
            $coordinates = $feature['geometry']['coordinates'] ?? [];
            if ($this->detectType($properties['categories'] ?? []) !== 'train'
                || ! isset($coordinates[0], $coordinates[1])) {
                continue;
            }

            foreach ($stops as $stop) {
                $tags = $stop['tags'] ?? [];
                if (($stop['type'] ?? null) !== 'node'
                    || (($tags['railway'] ?? null) !== 'stop' && ($tags['public_transport'] ?? null) !== 'stop_position')
                    || ! isset($stop['id'], $stop['lat'], $stop['lon'])) {
                    continue;
                }

                $stopId = 'node/' . $stop['id'];
                if (isset($routesByStop[$stopId])
                    || $this->distance((float) $coordinates[1], (float) $coordinates[0], (float) $stop['lat'], (float) $stop['lon']) > 500) {
                    continue;
                }

                $unmatchedStopIds[$stop['id']] = true;
            }
        }

        foreach ($fallbackElements as $element) {
            $tags = $element['tags'] ?? [];
            if (app(PoiCatalog::class)->osmType($tags) === 'train') {
                $coordinates = isset($element['lat'], $element['lon'])
                    ? [(float) $element['lon'], (float) $element['lat']]
                    : [
                        (float) ($element['center']['lon'] ?? 0),
                        (float) ($element['center']['lat'] ?? 0),
                    ];
                if ($coordinates[0] === 0.0 && $coordinates[1] === 0.0) {
                    continue;
                }

                foreach ($stops as $stop) {
                    $stopTags = $stop['tags'] ?? [];
                    if (($stop['type'] ?? null) !== 'node'
                        || (($stopTags['railway'] ?? null) !== 'stop' && ($stopTags['public_transport'] ?? null) !== 'stop_position')
                        || ! isset($stop['id'], $stop['lat'], $stop['lon'])) {
                        continue;
                    }

                    $stopId = 'node/' . $stop['id'];
                    if (! isset($routesByStop[$stopId])
                        && $this->distance($coordinates[1], $coordinates[0], (float) $stop['lat'], (float) $stop['lon']) <= 500) {
                        $unmatchedStopIds[$stop['id']] = true;
                    }
                }
            }
        }

        $foundRoutes = [];
        $available = true;
        foreach (array_keys($unmatchedStopIds) as $stopId) {
            try {
                $relations = Cache::remember(
                    'transit_stop_parent_relations:v1:' . $stopId,
                    now()->addHours(6),
                    function () use ($stopId): array {
                        $response = Http::withHeaders([
                            'User-Agent' => 'Symbiot/1.0 transit route lookup',
                        ])->timeout(10)
                            ->retry(1, 300)
                            ->acceptJson()
                            ->get("https://api.openstreetmap.org/api/0.6/node/{$stopId}/relations.json");

                        if (! $response->successful()) {
                            throw new \RuntimeException('OpenStreetMap node relations API returned HTTP ' . $response->status() . '.');
                        }

                        return $response->json('elements') ?? [];
                    }
                );

                foreach ($relations as $relation) {
                    if (($relation['tags']['route'] ?? null) === 'train') {
                        $foundRoutes[] = $relation;
                    }
                }
            } catch (\Throwable $e) {
                $available = false;
                Log::warning('OpenStreetMap train stop relation lookup failed', [
                    'stop_id' => $stopId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'routes' => collect($foundRoutes)
                ->unique(fn (array $route): string => 'relation/' . ($route['id'] ?? ''))
                ->values()
                ->all(),
            'available' => $available,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    private function transformOsm(
        array $elements,
        float $userLat,
        float $userLon,
        int $radius,
        array $types,
        array $transitStops = [],
        array $transitRoutes = [],
        bool $transitRoutesAvailable = true
    ): array {
        $catalog = app(PoiCatalog::class);
        $allowedTypes = array_fill_keys($types, true);
        $pois = [];
        $routesByStop = $this->routesByTransitStop($transitStops, $transitRoutes);

        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $type = $catalog->osmType($tags);

            if ($type === null || ! isset($allowedTypes[$type])) {
                continue;
            }

            $coordinates = isset($element['lat'], $element['lon'])
                ? [(float) $element['lon'], (float) $element['lat']]
                : [
                    (float) ($element['center']['lon'] ?? 0),
                    (float) ($element['center']['lat'] ?? 0),
                ];

            if ($coordinates[0] === 0.0 && $coordinates[1] === 0.0) {
                continue;
            }

            $meters = $this->distance($userLat, $userLon, $coordinates[1], $coordinates[0]);
            if ($meters > $radius) {
                continue;
            }

            $name = $tags['name'] ?? match ($type) {
                'speed_limit' => isset($tags['maxspeed']) ? 'Limită ' . $tags['maxspeed'] : $catalog->categories()[$type]['label'],
                default => $catalog->categories()[$type]['label'],
            };
            $lodgingAddress = array_filter([
                trim(implode(' ', array_filter([$tags['addr:housenumber'] ?? null, $tags['addr:street'] ?? null]))),
                $tags['addr:postcode'] ?? null,
                $tags['addr:city'] ?? null,
            ]);

            $pois[] = [
                'id' => ($element['type'] ?? 'element') . '/' . ($element['id'] ?? 0),
                'name' => $name,
                'type' => $type,
                'routes' => $this->routesNearTransitStop(
                    $coordinates[1],
                    $coordinates[0],
                    $type,
                    $transitStops,
                    $routesByStop
                ),
                'routes_available' => $transitRoutesAvailable,
                'coordinates' => ['lat' => $coordinates[1], 'lon' => $coordinates[0]],
                'distance' => [
                    'meters' => (int) round($meters),
                    'km' => round($meters / 1000, 2),
                    'formatted' => $meters < 1000
                        ? round($meters) . ' m'
                        : round($meters / 1000, 1) . ' km',
                ],
                'address' => [
                    'formatted' => $type === 'lodging' && $lodgingAddress !== []
                        ? implode(', ', $lodgingAddress)
                        : ($tags['name'] ?? null),
                    'city' => $tags['addr:city'] ?? null,
                    'country' => $tags['addr:country'] ?? null,
                ],
                'details' => $type === 'lodging' ? $this->extractLodgingDetails($tags) : [],
            ];
        }

        return collect($pois)->unique('id')->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @param  list<array<string, mixed>>  $busStops
     * @param  list<array<string, mixed>>  $busRoutes
     * @return list<array<string, mixed>>
     */
    private function transform(
        array $features,
        float $userLat,
        float $userLon,
        array $transitStops = [],
        array $transitRoutes = [],
        bool $transitRoutesAvailable = true
    ): array {
        $out = [];
        $routesByStop = $this->routesByTransitStop($transitStops, $transitRoutes);

        foreach ($features as $feature) {

            $props = $feature['properties'] ?? [];
            $coords = $feature['geometry']['coordinates'] ?? null;

            if (! is_array($coords) || count($coords) !== 2) {
                continue;
            }

            [$lon, $lat] = $coords;

            $meters = $this->distance($userLat, $userLon, $lat, $lon);
            $routes = $this->routesNearTransitStop(
                $lat,
                $lon,
                $this->detectType($props['categories'] ?? []),
                $transitStops,
                $routesByStop
            );

            $out[] = [
                'id' => $props['place_id'] ?? null,

                'name' => $props['name']
                    ?? $props['formatted']
                    ?? $props['street']
                    ?? 'Unknown',

                'type' => $this->detectType($props['categories'] ?? []),
                'routes' => $routes,
                'routes_available' => $transitRoutesAvailable,

                'coordinates' => [
                    'lat' => $lat,
                    'lon' => $lon,
                ],

                'distance' => [
                    'meters' => (int) round($meters),
                    'km' => round($meters / 1000, 2),
                    'formatted' => $meters < 1000
                        ? round($meters) . ' m'
                        : round($meters / 1000, 1) . ' km',
                ],

                'address' => [
                    'formatted' => $props['formatted'] ?? null,
                    'city' => $props['city'] ?? null,
                    'country' => $props['country'] ?? null,
                ],
                'details' => $this->detectType($props['categories'] ?? []) === 'lodging'
                    ? $this->extractLodgingDetails($props)
                    : [],
            ];
        }

        usort($out, fn ($a, $b) => $a['distance']['meters'] <=> $b['distance']['meters']
        );

        return $out;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, string>
     */
    private function extractLodgingDetails(array $properties): array
    {
        $raw = is_array($properties['datasource']['raw'] ?? null)
            ? $properties['datasource']['raw']
            : [];
        $contact = is_array($properties['contact'] ?? null)
            ? $properties['contact']
            : [];
        $details = [
            'accommodation_type' => $properties['accommodation_type'] ?? $properties['tourism'] ?? $raw['tourism'] ?? null,
            'address' => $properties['formatted'] ?? null,
            'phone' => $properties['phone'] ?? $properties['contact:phone'] ?? $contact['phone'] ?? $raw['phone'] ?? $raw['contact:phone'] ?? null,
            'email' => $properties['email'] ?? $properties['contact:email'] ?? $contact['email'] ?? $raw['email'] ?? $raw['contact:email'] ?? null,
            'website' => $properties['website'] ?? $properties['contact:website'] ?? $contact['website'] ?? $raw['website'] ?? $raw['contact:website'] ?? null,
            'opening_hours' => $properties['opening_hours'] ?? $raw['opening_hours'] ?? null,
            'operator' => $properties['operator'] ?? $raw['operator'] ?? null,
            'brand' => $properties['brand'] ?? $raw['brand'] ?? null,
            'stars' => $properties['stars'] ?? $raw['stars'] ?? null,
            'rooms' => $properties['rooms'] ?? $raw['rooms'] ?? null,
            'beds' => $properties['beds'] ?? $raw['beds'] ?? null,
            'check_in' => $properties['check_in'] ?? $raw['check-in'] ?? null,
            'check_out' => $properties['check_out'] ?? $raw['check-out'] ?? null,
            'wheelchair' => $properties['wheelchair'] ?? $raw['wheelchair'] ?? null,
            'internet_access' => $properties['internet_access'] ?? $raw['internet_access'] ?? null,
            'description' => $properties['description'] ?? $raw['description'] ?? null,
        ];

        return collect($details)
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @param  list<array<string, mixed>>  $routes
     * @return array<string, list<array{id: string, label: string, direction: ?string, updated_at: ?string, status: string}>>
     */
    private function routesByTransitStop(array $stops, array $routes): array
    {
        $routeRefsByStop = [];
        $stopIds = [];

        foreach ($stops as $stop) {
            if (isset($stop['type'], $stop['id'])) {
                $stopIds[$stop['type'] . '/' . $stop['id']] = true;
            }
        }

        foreach ($routes as $route) {
            $tags = $route['tags'] ?? [];
            $routeRef = trim((string) ($tags['ref'] ?? ''));
            $routeRef = preg_replace('/[\s-]+$/u', '', $routeRef) ?? $routeRef;
            if ($routeRef === '') {
                $routeRef = trim((string) ($tags['name'] ?? ''));
            }
            if ($routeRef === '') {
                continue;
            }
            $routeMode = match ($tags['route'] ?? '') {
                'bus' => 'Autobuz',
                'trolleybus' => 'Troleibuz',
                'subway' => 'Metrou',
                'train' => 'Tren',
                'light_rail' => 'Tren urban',
                'tram' => 'Tramvai',
                'monorail' => 'Monorail',
                'funicular' => 'Funicular',
                'airline' => 'Companie aeriană',
                default => 'Rută',
            };
            $routeLabel = $routeMode . ' ' . $routeRef;

            foreach ($route['members'] ?? [] as $member) {
                $memberId = ($member['type'] ?? '') . '/' . ($member['ref'] ?? '');
                if (isset($stopIds[$memberId])) {
                    $routeRefsByStop[$memberId][] = [
                        'id' => 'relation/' . ($route['id'] ?? ''),
                        'label' => $routeLabel,
                        'direction' => isset($tags['from'], $tags['to'])
                            ? trim((string) $tags['from']) . ' → ' . trim((string) $tags['to'])
                            : null,
                        'updated_at' => $route['timestamp'] ?? null,
                        'status' => $this->transitRouteStatus($tags),
                    ];
                }
            }
        }

        foreach ($routeRefsByStop as &$routeRefs) {
            $routeRefs = collect($routeRefs)->unique('id')->values()->all();
        }
        unset($routeRefs);

        return $routeRefsByStop;
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @param  array<string, list<array{id: string, label: string, direction: ?string, updated_at: ?string, status: string}>>  $routesByStop
     * @return list<array{id: string, label: string, direction: ?string, updated_at: ?string, status: string}>
     */
    private function routesNearTransitStop(float $lat, float $lon, string $type, array $stops, array $routesByStop): array
    {
        $routes = [];
        $allowedRoutePrefixes = match ($type) {
            'bus' => ['Autobuz ', 'Troleibuz '],
            'subway' => ['Metrou '],
            'train' => ['Tren ', 'Tren urban ', 'Tramvai ', 'Monorail ', 'Funicular '],
            'airport' => ['Companie aeriană '],
            default => [],
        };
        if ($allowedRoutePrefixes === []) {
            return [];
        }

        foreach ($stops as $stop) {
            $stopId = ($stop['type'] ?? '') . '/' . ($stop['id'] ?? '');
            if (! isset($routesByStop[$stopId])) {
                continue;
            }

            $stopCoordinates = isset($stop['lat'], $stop['lon'])
                ? [(float) $stop['lon'], (float) $stop['lat']]
                : [
                    (float) ($stop['center']['lon'] ?? 0),
                    (float) ($stop['center']['lat'] ?? 0),
                ];
            if ($stopCoordinates[0] === 0.0 && $stopCoordinates[1] === 0.0) {
                continue;
            }

            $matchDistance = $type === 'airport' ? 5000 : 300;
            if ($this->distance($lat, $lon, $stopCoordinates[1], $stopCoordinates[0]) <= $matchDistance) {
                $routes = array_merge(
                    $routes,
                    array_filter(
                        $routesByStop[$stopId],
                        fn (array $route): bool => collect($allowedRoutePrefixes)->contains(
                            fn (string $prefix): bool => str_starts_with($route['label'], $prefix)
                        )
                    )
                );
            }
        }

        return collect($routes)->unique('id')->values()->all();
    }

    /**
     * @param  array<string, mixed>  $route
     * @return list<array{0: float, 1: float}>
     */
    private function routeCoordinates(array $route): array
    {
        $coordinates = [];

        foreach ($route['members'] ?? [] as $member) {
            foreach ($member['geometry'] ?? [] as $point) {
                if (isset($point['lon'], $point['lat'])) {
                    $coordinates[] = [(float) $point['lon'], (float) $point['lat']];
                }
            }
        }

        return $coordinates;
    }

    /** @param array<string, mixed> $tags */
    private function transitRouteStatus(array $tags): string
    {
        foreach (['disused', 'abandoned', 'construction', 'proposed'] as $lifecycleTag) {
            if (in_array(strtolower((string) ($tags[$lifecycleTag] ?? '')), ['yes', 'true', '1'], true)
                || array_key_exists($lifecycleTag . ':route', $tags)) {
                return 'possibly_inactive';
            }
        }

        return 'listed_in_osm';
    }

    /** @param list<string> $categories */
    private function detectType(array $categories): string
    {
        return app(PoiCatalog::class)->geoapifyType($categories);
    }

    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2 +
            cos(deg2rad($lat1)) *
            cos(deg2rad($lat2)) *
            sin($dLon / 2) ** 2;

        return $R * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
