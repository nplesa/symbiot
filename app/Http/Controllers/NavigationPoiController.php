<?php

namespace App\Http\Controllers;

use App\Models\Route as PlannedRoute;
use App\Services\PoiCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NavigationPoiController extends Controller
{
    public function __construct(private readonly PoiCatalog $catalog) {}

    public function index(PlannedRoute $route): JsonResponse
    {
        abort_unless((int) $route->user_id === (int) auth()->id(), 404);

        $routeCoordinates = $this->flattenCoordinates($route->geometry);
        if (count($routeCoordinates) < 2) {
            return response()->json(['pois' => []]);
        }

        $bbox = $this->boundingBox($routeCoordinates);

        $cacheKey = 'navigation-pois:v10:' . md5(json_encode($this->catalog->navigation(), JSON_THROW_ON_ERROR)) . ':' . $route->id . ':' . $route->updated_at?->timestamp;

        try {
            $pois = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($bbox, $routeCoordinates): array {
                $query = $this->overpassQuery($bbox);
                $response = Http::withHeaders([
                    'User-Agent' => 'Symbiot/1.0 navigation POI map',
                ])->timeout(25)
                    ->retry(1, 300)
                    ->acceptJson()
                    ->get('https://overpass-api.de/api/interpreter', ['data' => $query]);

                if (! $response->successful()) {
                    throw new RuntimeException('Serviciul POI a returnat HTTP ' . $response->status() . '.');
                }

                return $this->transform($response->json('elements') ?? [], $routeCoordinates);
            });
        } catch (\Throwable $exception) {
            report($exception);
            $pois = [];
        }

        return response()->json(['pois' => $pois]);
    }

    /** @param array{minLat: float, minLon: float, maxLat: float, maxLon: float} $bbox */
    private function overpassQuery(array $bbox): string
    {
        $box = implode(',', [
            $bbox['minLat'],
            $bbox['minLon'],
            $bbox['maxLat'],
            $bbox['maxLon'],
        ]);

        $queries = [];
        foreach ($this->catalog->navigation() as $category) {
            foreach ($category['osm'] as $selector) {
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
        }

        return '[out:json][timeout:25];(' . implode('', array_unique($queries)) . ');out center tags;';
    }

    /** @param list<array<string, mixed>> $elements @param list<array{0: float, 1: float}> $routeCoordinates */
    private function transform(array $elements, array $routeCoordinates): array
    {
        $pois = [];

        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $coordinates = isset($element['lat'], $element['lon'])
                ? [(float) $element['lon'], (float) $element['lat']]
                : [
                    (float) ($element['center']['lon'] ?? 0),
                    (float) ($element['center']['lat'] ?? 0),
                ];

            if ($coordinates[0] === 0.0 && $coordinates[1] === 0.0) {
                continue;
            }

            $type = $this->catalog->osmType($tags);
            if ($type === null) {
                continue;
            }
            $maxDistance = $this->catalog->navigation()[$type]['distance'];

            if ($this->distanceToRoute($coordinates, $routeCoordinates) > $maxDistance) {
                continue;
            }

            $pois[] = [
                'id' => ($element['type'] ?? 'element') . '/' . ($element['id'] ?? 0),
                'type' => $type,
                'name' => $this->name($type, $tags),
                'place' => $tags['place'] ?? null,
                'coordinates' => ['lon' => $coordinates[0], 'lat' => $coordinates[1]],
            ];
        }

        return collect($pois)->unique('id')->values()->all();
    }

    /** @param array<string, string> $tags */
    private function name(string $type, array $tags): string
    {
        if (isset($tags['name']) && $tags['name'] !== '') {
            return $tags['name'];
        }

        if ($type === 'speed_limit' && isset($tags['maxspeed'])) {
            return 'Limită ' . $tags['maxspeed'];
        }
        if ($type === 'supermarket') {
            return 'Supermarket';
        }

        return $this->catalog->categories()[$type]['label'];
    }

    /** @param list<array{0: float, 1: float}> $coordinates */
    private function boundingBox(array $coordinates): array
    {
        $longitudes = array_column($coordinates, 0);
        $latitudes = array_column($coordinates, 1);

        $padding = max(array_column($this->catalog->navigation(), 'distance')) / 110000;
        $longitudePadding = $padding / max(0.01, cos(deg2rad(max(abs(min($latitudes)), abs(max($latitudes))))));

        return [
            'minLat' => max(-90, min($latitudes) - $padding),
            'minLon' => max(-180, min($longitudes) - $longitudePadding),
            'maxLat' => min(90, max($latitudes) + $padding),
            'maxLon' => min(180, max($longitudes) + $longitudePadding),
        ];
    }

    /** @param array{0: float, 1: float} $point @param list<array{0: float, 1: float}> $route */
    private function distanceToRoute(array $point, array $route): float
    {
        $minimum = INF;
        $pointLat = deg2rad($point[1]);
        $longitudeScale = 111320 * cos($pointLat);
        $latitudeScale = 111320;

        for ($index = 1, $count = count($route); $index < $count; $index++) {
            $start = $route[$index - 1];
            $end = $route[$index];
            $startX = ($start[0] - $point[0]) * $longitudeScale;
            $startY = ($start[1] - $point[1]) * $latitudeScale;
            $endX = ($end[0] - $point[0]) * $longitudeScale;
            $endY = ($end[1] - $point[1]) * $latitudeScale;
            $deltaX = $endX - $startX;
            $deltaY = $endY - $startY;
            $lengthSquared = ($deltaX * $deltaX) + ($deltaY * $deltaY);
            $position = $lengthSquared > 0
                ? max(0, min(1, -(($startX * $deltaX) + ($startY * $deltaY)) / $lengthSquared))
                : 0;
            $distance = hypot($startX + ($position * $deltaX), $startY + ($position * $deltaY));
            $minimum = min($minimum, $distance);
        }

        return $minimum;
    }

    /** @param array<string, mixed>|null $geometry */
    private function flattenCoordinates(?array $geometry): array
    {
        if (! $geometry) {
            return [];
        }
        if (($geometry['type'] ?? null) === 'Feature') {
            return $this->flattenCoordinates($geometry['geometry'] ?? null);
        }
        if (($geometry['type'] ?? null) === 'FeatureCollection') {
            $coordinates = [];
            foreach ($geometry['features'] ?? [] as $feature) {
                $coordinates = array_merge($coordinates, $this->flattenCoordinates($feature));
            }

            return $coordinates;
        }
        if (($geometry['type'] ?? null) === 'LineString') {
            return $geometry['coordinates'] ?? [];
        }
        if (($geometry['type'] ?? null) === 'MultiLineString') {
            $coordinates = [];
            foreach ($geometry['coordinates'] ?? [] as $line) {
                $coordinates = array_merge($coordinates, $line);
            }

            return $coordinates;
        }

        return [];
    }
}
