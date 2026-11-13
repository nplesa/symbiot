<?php

namespace App\Services\Poi;

use App\Support\GeoDistance;
use App\Services\PoiCatalog;
use App\Services\RailTimetableProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Nearby POIs from Geoapify and OpenStreetMap, with the transit routes serving transport stops. */
class PoiSearch
{
    public function __construct(private readonly OsmTransitRoutes $transitRoutes) {}

    /**
     * @param  list<string>  $selectedFilters  keys of PoiCatalog::filters()
     * @return list<array<string, mixed>>
     *
     * @throws \Throwable when a provider fails in a way that cannot be degraded
     */
    public function search(float $lat, float $lon, int $radius, array $selectedFilters, bool $includeTransitRoutes): array
    {
        $catalog = app(PoiCatalog::class);
        $filters = $catalog->filters();
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
            'transport_poi:v16:%s:%s:%s:%s:%s',
            round($lat, 4),
            round($lon, 4),
            $radius,
            md5(implode(',', $selectedFilters)),
            $includeTransitRoutes ? 'routes' : 'no-routes'
        );
        $transitTypes = array_values(array_intersect($poiTypes, ['bus', 'subway', 'train', 'airport']));

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
            $routeLookupTypes = array_values(array_diff($transitTypes, ['airport']));
            $transitRouteData = $routeLookupTypes !== [] && $includeTransitRoutes
                ? $this->transitRoutes->fetch(
                    $lat,
                    $lon,
                    $radius,
                    $routeLookupTypes,
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
            // Incomplete transit data: keep it briefly so an Overpass outage is not
            // re-queried on every request, then retry for complete routes.
            Cache::put($cacheKey, $data, now()->addMinute());
        }

        $result = array_merge(
            $this->transform(
                $data['features'] ?? [],
                $lat,
                $lon,
                $radius,
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

        // Train markers are loaded with include_routes=false, and the station popup reads
        // train_services from the marker, so the (cached) timetable is attached either way.
        if (in_array('train', $poiTypes, true)) {
            $result = app(RailTimetableProvider::class)->addScheduledServices($result);
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function fetchFromGeoapify(float $lat, float $lon, int $radius, string $categories): array
    {
        $categoryList = array_filter(explode(',', $categories));
        $airportSearch = collect($categoryList)->contains(
            fn (string $category): bool => $category === 'airport' || str_starts_with($category, 'airport.')
        );
        // Airports are sparse; a single circle query is enough and avoids a
        // series of redundant Geoapify grid requests on large search radii.
        $points = $airportSearch ? [[$lat, $lon]] : $this->buildGridPoints($lat, $lon, $radius);
        $timeoutSeconds = $airportSearch ? 8 : (trim($categories) === 'public_transport.train' ? 5 : 10);
        $all = [];

        foreach (array_chunk($categoryList, 100) as $categoryChunk) {
            $categoryList = implode(',', $categoryChunk);

            foreach ($points as [$pLat, $pLon]) {
                try {
                    $request = Http::connectTimeout(3)->timeout($timeoutSeconds)->acceptJson();
                    if (! $airportSearch) {
                        $request->retry(1, 200);
                    }
                    $response = $request->get('https://api.geoapify.com/v2/places', [
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

        $query = '[out:json][timeout:8];(' . implode('', array_unique($queries)) . ');out center tags;';
        $lastError = null;
        foreach ((array) config('osm.overpass_endpoints') as $endpoint) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Symbiot/1.0 nearby POI map',
                ])->connectTimeout(3)
                    ->timeout(10)
                    ->acceptJson()
                    ->get($endpoint, ['data' => $query]);

                if (! $response->successful()) {
                    throw new \RuntimeException('OpenStreetMap POI service returned HTTP ' . $response->status() . '.');
                }

                return $response->json('elements') ?? [];
            } catch (\Throwable $exception) {
                $lastError = $exception;
            }
        }

        // OSM supplements Geoapify here; an Overpass outage should not turn a
        // usable POI response into HTTP 500 or block the selected category.
        Log::warning('OpenStreetMap POI fallback unavailable', [
            'message' => $lastError?->getMessage(),
        ]);

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @param  list<string>  $types
     * @param  list<array<string, mixed>>  $transitStops
     * @param  list<array<string, mixed>>  $transitRoutes
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
        $routesByStop = $this->transitRoutes->routesByStop($transitStops, $transitRoutes);

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

            $meters = GeoDistance::meters($userLat, $userLon, $coordinates[1], $coordinates[0]);
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
                'routes' => $this->transitRoutes->routesNearStop(
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
                'details' => $type === 'lodging'
                    ? $this->extractLodgingDetails($tags)
                    : ($type === 'airport' ? $this->extractAirportDetails($tags) : []),
            ];
        }

        return collect($pois)->unique('id')->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @param  list<array<string, mixed>>  $transitStops
     * @param  list<array<string, mixed>>  $transitRoutes
     * @return list<array<string, mixed>>
     */
    private function transform(
        array $features,
        float $userLat,
        float $userLon,
        int $radius,
        array $transitStops = [],
        array $transitRoutes = [],
        bool $transitRoutesAvailable = true
    ): array {
        $out = [];
        $routesByStop = $this->transitRoutes->routesByStop($transitStops, $transitRoutes);

        foreach ($features as $feature) {

            $props = $feature['properties'] ?? [];
            $coords = $feature['geometry']['coordinates'] ?? null;

            if (! is_array($coords) || count($coords) !== 2) {
                continue;
            }

            [$lon, $lat] = $coords;

            $meters = GeoDistance::meters($userLat, $userLon, $lat, $lon);
            // Large radii query a grid of offset circles, which also returns POIs outside the requested circle.
            if ($meters > $radius) {
                continue;
            }

            $routes = $this->transitRoutes->routesNearStop(
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
                'details' => match ($this->detectType($props['categories'] ?? [])) {
                    'lodging' => $this->extractLodgingDetails($props),
                    'airport' => $this->extractAirportDetails(is_array($props['datasource']['raw'] ?? null)
                        ? array_merge(
                            $props['datasource']['raw'],
                            is_array($props['datasource']['raw']['tags'] ?? null) ? $props['datasource']['raw']['tags'] : [],
                            $props,
                        )
                        : $props),
                    default => [],
                },
            ];
        }

        usort($out, fn ($a, $b) => $a['distance']['meters'] <=> $b['distance']['meters']
        );

        return $out;
    }

    /**
     * Extract stable public airport identifiers from Geoapify or OSM tags.
     * We intentionally do not treat a generic OSM `ref` as an IATA/ICAO code.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, string>
     */
    private function extractAirportDetails(array $properties): array
    {
        $iata = strtoupper(trim((string) ($properties['iata'] ?? $properties['iata_code'] ?? $properties['airport_iata'] ?? '')));
        $icao = strtoupper(trim((string) ($properties['icao'] ?? $properties['icao_code'] ?? $properties['airport_icao'] ?? '')));

        return array_filter([
            'iata' => preg_match('/^[A-Z]{3}$/', $iata) ? $iata : null,
            'icao' => preg_match('/^[A-Z]{4}$/', $icao) ? $icao : null,
            'operator' => isset($properties['operator']) ? (string) $properties['operator'] : null,
            'website' => isset($properties['website']) ? (string) $properties['website'] : null,
        ], static fn ($value): bool => is_string($value) && $value !== '');
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

    /** @param list<string> $categories */
    private function detectType(array $categories): string
    {
        return app(PoiCatalog::class)->geoapifyType($categories);
    }
}
