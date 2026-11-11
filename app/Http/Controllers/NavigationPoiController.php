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
    /** Route vertices closer than this to the simplified line are dropped (well below the 150 m smallest POI distance). */
    private const SIMPLIFY_TOLERANCE_METERS = 15;

    /** The route is queried as a corridor of boxes, one per chunk of this length. */
    private const CHUNK_LENGTH_METERS = 20000;

    private const METERS_PER_DEGREE = 111320;

    public function __construct(private readonly PoiCatalog $catalog) {}

    public function index(PlannedRoute $route): JsonResponse
    {
        abort_unless((int) $route->user_id === (int) auth()->id(), 404);

        $routeCoordinates = $this->simplify($this->flattenCoordinates($route->geometry));
        if (count($routeCoordinates) < 2) {
            return response()->json(['pois' => []]);
        }

        $chunks = $this->corridorChunks($routeCoordinates);

        $cacheKey = 'navigation-pois:v11:' . md5(json_encode($this->catalog->navigation(), JSON_THROW_ON_ERROR)) . ':' . $route->id . ':' . $route->updated_at?->timestamp;

        $pois = Cache::get($cacheKey);
        if (is_array($pois)) {
            return response()->json(['pois' => $pois]);
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Symbiot/1.0 navigation POI map',
            ])->connectTimeout(5)
                ->timeout(25)
                ->retry(1, 300)
                ->acceptJson()
                ->asForm()
                ->post('https://overpass-api.de/api/interpreter', ['data' => $this->overpassQuery($chunks)]);

            if (! $response->successful()) {
                throw new RuntimeException('Serviciul POI a returnat HTTP ' . $response->status() . '.');
            }

            $pois = $this->transform($response->json('elements') ?? [], $chunks);
            // The key includes updated_at, so editing the route invalidates this entry.
            Cache::put($cacheKey, $pois, now()->addHours(6));
        } catch (\Throwable $exception) {
            report($exception);
            $pois = [];
            // Avoid re-running a slow failing Overpass query on every page reload.
            Cache::put($cacheKey, $pois, now()->addMinutes(2));
        }

        return response()->json(['pois' => $pois]);
    }

    /** @param list<array{box: array{minLat: float, minLon: float, maxLat: float, maxLon: float}, points: list<array{0: float, 1: float}>}> $chunks */
    private function overpassQuery(array $chunks): string
    {
        $selectors = [];
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
                $selectors[] = 'nwr' . $filters;
            }
        }

        $queries = [];
        foreach ($chunks as $chunk) {
            $box = implode(',', [
                round($chunk['box']['minLat'], 6),
                round($chunk['box']['minLon'], 6),
                round($chunk['box']['maxLat'], 6),
                round($chunk['box']['maxLon'], 6),
            ]);
            foreach (array_unique($selectors) as $selector) {
                $queries[] = $selector . '(' . $box . ');';
            }
        }

        return '[out:json][timeout:25];(' . implode('', $queries) . ');out center tags;';
    }

    /**
     * Splits the route into consecutive chunks, each with a bounding box padded by the
     * largest POI distance, so only a corridor along the route is queried and checked.
     *
     * @param  list<array{0: float, 1: float}>  $coordinates
     * @return list<array{box: array{minLat: float, minLon: float, maxLat: float, maxLon: float}, points: list<array{0: float, 1: float}>}>
     */
    private function corridorChunks(array $coordinates): array
    {
        $coordinates = $this->densify($coordinates, self::CHUNK_LENGTH_METERS / 2);
        $chunks = [];
        $points = [$coordinates[0]];
        $length = 0.0;

        for ($index = 1, $count = count($coordinates); $index < $count; $index++) {
            $points[] = $coordinates[$index];
            $length += $this->meters($coordinates[$index - 1], $coordinates[$index]);

            if ($length >= self::CHUNK_LENGTH_METERS && $index < $count - 1) {
                $chunks[] = $points;
                // Chunks share their boundary vertex so no segment is lost.
                $points = [$coordinates[$index]];
                $length = 0.0;
            }
        }
        $chunks[] = $points;

        return array_map(fn (array $chunkPoints): array => [
            'box' => $this->boundingBox($chunkPoints),
            'points' => $chunkPoints,
        ], $chunks);
    }

    /** @param list<array<string, mixed>> $elements @param list<array{box: array{minLat: float, minLon: float, maxLat: float, maxLon: float}, points: list<array{0: float, 1: float}>}> $chunks */
    private function transform(array $elements, array $chunks): array
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

            if ($this->distanceToChunks($coordinates, $chunks) > $maxDistance) {
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

        $padding = $this->maxPoiDistance() / 110000;
        $longitudePadding = $padding / max(0.01, cos(deg2rad(max(abs(min($latitudes)), abs(max($latitudes))))));

        return [
            'minLat' => max(-90, min($latitudes) - $padding),
            'minLon' => max(-180, min($longitudes) - $longitudePadding),
            'maxLat' => min(90, max($latitudes) + $padding),
            'maxLon' => min(180, max($longitudes) + $longitudePadding),
        ];
    }

    private function maxPoiDistance(): float
    {
        return (float) max(array_column($this->catalog->navigation(), 'distance'));
    }

    /**
     * Distance to the route, checking only chunks whose padded box contains the point.
     *
     * @param  array{0: float, 1: float}  $point
     * @param  list<array{box: array{minLat: float, minLon: float, maxLat: float, maxLon: float}, points: list<array{0: float, 1: float}>}>  $chunks
     */
    private function distanceToChunks(array $point, array $chunks): float
    {
        $minimum = INF;
        foreach ($chunks as $chunk) {
            $box = $chunk['box'];
            if ($point[1] < $box['minLat'] || $point[1] > $box['maxLat']
                || $point[0] < $box['minLon'] || $point[0] > $box['maxLon']) {
                continue;
            }

            $minimum = min($minimum, $this->distanceToRoute($point, $chunk['points']));
        }

        return $minimum;
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

    /**
     * Inserts points so no segment is longer than $maxSegmentMeters. Simplified straight
     * stretches can be very long and would otherwise produce one huge chunk box.
     *
     * @param  list<array{0: float, 1: float}>  $coordinates
     * @return list<array{0: float, 1: float}>
     */
    private function densify(array $coordinates, float $maxSegmentMeters): array
    {
        $result = [$coordinates[0]];
        for ($index = 1, $count = count($coordinates); $index < $count; $index++) {
            $from = $coordinates[$index - 1];
            $to = $coordinates[$index];
            $steps = (int) ceil($this->meters($from, $to) / $maxSegmentMeters);
            for ($step = 1; $step < $steps; $step++) {
                $ratio = $step / $steps;
                $result[] = [
                    $from[0] + ($to[0] - $from[0]) * $ratio,
                    $from[1] + ($to[1] - $from[1]) * $ratio,
                ];
            }
            $result[] = $to;
        }

        return $result;
    }

    /** @param array{0: float, 1: float} $from @param array{0: float, 1: float} $to */
    private function meters(array $from, array $to): float
    {
        $longitudeScale = self::METERS_PER_DEGREE * cos(deg2rad(($from[1] + $to[1]) / 2));

        return hypot(($to[0] - $from[0]) * $longitudeScale, ($to[1] - $from[1]) * self::METERS_PER_DEGREE);
    }

    /**
     * Douglas–Peucker simplification (iterative, so 10k-point routes cannot exhaust the stack).
     *
     * @param  list<array{0: float, 1: float}>  $coordinates
     * @return list<array{0: float, 1: float}>
     */
    private function simplify(array $coordinates): array
    {
        $count = count($coordinates);
        if ($count < 3) {
            return $coordinates;
        }

        $keep = array_fill(0, $count, false);
        $keep[0] = $keep[$count - 1] = true;
        $stack = [[0, $count - 1]];

        while ($stack !== []) {
            [$first, $last] = array_pop($stack);
            $farthestIndex = null;
            $farthestDistance = self::SIMPLIFY_TOLERANCE_METERS;

            for ($index = $first + 1; $index < $last; $index++) {
                $distance = $this->distanceToRoute($coordinates[$index], [$coordinates[$first], $coordinates[$last]]);
                if ($distance > $farthestDistance) {
                    $farthestIndex = $index;
                    $farthestDistance = $distance;
                }
            }

            if ($farthestIndex !== null) {
                $keep[$farthestIndex] = true;
                $stack[] = [$first, $farthestIndex];
                $stack[] = [$farthestIndex, $last];
            }
        }

        return array_values(array_filter(
            $coordinates,
            fn (int $index): bool => $keep[$index],
            ARRAY_FILTER_USE_KEY
        ));
    }

    /**
     * Valid [lon, lat] pairs only, without consecutive duplicates.
     *
     * @param  array<string, mixed>|null  $geometry
     * @return list<array{0: float, 1: float}>
     */
    private function flattenCoordinates(?array $geometry): array
    {
        $coordinates = [];
        foreach ($this->rawCoordinates($geometry) as $point) {
            if (! is_array($point) || ! isset($point[0], $point[1]) || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                continue;
            }

            $lon = (float) $point[0];
            $lat = (float) $point[1];
            if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
                continue;
            }

            if ($coordinates !== [] && end($coordinates) === [$lon, $lat]) {
                continue;
            }
            $coordinates[] = [$lon, $lat];
        }

        return $coordinates;
    }

    /** @param array<string, mixed>|null $geometry */
    private function rawCoordinates(?array $geometry): array
    {
        if (! $geometry) {
            return [];
        }
        if (($geometry['type'] ?? null) === 'Feature') {
            return $this->rawCoordinates($geometry['geometry'] ?? null);
        }
        if (($geometry['type'] ?? null) === 'FeatureCollection') {
            $coordinates = [];
            foreach ($geometry['features'] ?? [] as $feature) {
                $coordinates = array_merge($coordinates, $this->rawCoordinates($feature));
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
