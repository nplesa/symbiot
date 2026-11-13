<?php

namespace App\Services\Poi;

use App\Support\GeoDistance;
use App\Services\PoiCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Public transport stops and the route relations serving them, from OpenStreetMap (Overpass + OSM API). */
class OsmTransitRoutes
{
    /**
     * Route geometry of one OSM route relation, cached for 6 hours.
     *
     * @return array{segments: list<list<array{float, float}>>, updated_at: ?string}|null null when OSM has no geometry
     *
     * @throws \Throwable when OpenStreetMap cannot be reached or the relation is missing
     */
    public function routeGeometry(int $relationId): ?array
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
                $elements = is_array($payload['elements'] ?? null) ? $payload['elements'] : [];
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
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);

            throw $e;
        }

        if ($route['segments'] === []) {
            Cache::forget($cacheKey);

            return null;
        }

        return $route;
    }

    /**
     * @param  list<string>  $types
     * @param  list<array<string, mixed>>  $features
     * @param  list<array<string, mixed>>  $fallbackElements
     * @return array{stops: list<array<string, mixed>>, routes: list<array<string, mixed>>, available: bool}
     */
    public function fetch(
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
        $stopSelectors = $this->stopSelectors($routeTypes);

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
        foreach ((array) config('osm.overpass_endpoints') as $endpoint) {
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
     * @param  list<array<string, mixed>>  $stops
     * @param  list<array<string, mixed>>  $routes
     * @return array<string, list<array{id: string, label: string, direction: ?string, updated_at: ?string, status: string}>>
     */
    public function routesByStop(array $stops, array $routes): array
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
                        'status' => $this->routeStatus($tags),
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
    public function routesNearStop(float $lat, float $lon, string $type, array $stops, array $routesByStop): array
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
            if (GeoDistance::meters($lat, $lon, $stopCoordinates[1], $stopCoordinates[0]) <= $matchDistance) {
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
     * @param  list<string>  $routeTypes
     * @return list<string>
     */
    private function stopSelectors(array $routeTypes): array
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
        $routesByStop = $this->routesByStop($stops, $routes);
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
                    || GeoDistance::meters((float) $coordinates[1], (float) $coordinates[0], (float) $stop['lat'], (float) $stop['lon']) > 500) {
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
                        && GeoDistance::meters($coordinates[1], $coordinates[0], (float) $stop['lat'], (float) $stop['lon']) <= 500) {
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

    /** @param array<string, mixed> $tags */
    private function routeStatus(array $tags): string
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
}
