<?php

namespace App\Services;

use App\Models\CityBoundary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * City boundaries for "Whole city": read from city_boundaries, fetched from Geoapify only the first
 * time a city is asked for (then shared by every user and every point inside it). Only those Geoapify
 * fetches are rate limited; a city that was not found is remembered for a few minutes only.
 */
class CityBoundaries
{
    /** Geoapify fetches per user (or IP) and minute; reading stored boundaries is not limited. */
    public const FETCHES_PER_MINUTE = 10;

    public const NOT_FOUND_MINUTES = 10;

    /**
     * The GeoJSON feature of the city, or null when Geoapify has no boundary for it.
     *
     * @throws CityBoundaryRateLimited when a new city would exceed the fetch limit
     * @throws RuntimeException when Geoapify cannot be reached
     * @return array<string, mixed>|null
     */
    public function find(?string $geoapifyId, ?float $lat, ?float $lon, ?string $name, string $limiterKey): ?array
    {
        $stored = $this->stored($geoapifyId, $lat, $lon, $name);
        if ($stored !== null) {
            return $stored->feature;
        }

        $missKey = 'city-boundary:miss:' . sha1(($geoapifyId ?? '') . '|' . $lat . ',' . $lon . '|' . $name);
        if (Cache::has($missKey)) {
            return null;
        }

        $limiter = 'city-boundary-fetch:' . $limiterKey;
        if (RateLimiter::tooManyAttempts($limiter, self::FETCHES_PER_MINUTE)) {
            throw new CityBoundaryRateLimited(RateLimiter::availableIn($limiter));
        }
        RateLimiter::hit($limiter, 60);

        $feature = $this->fetch($geoapifyId, $lat, $lon, $name);
        if ($feature === null) {
            Cache::put($missKey, true, now()->addMinutes(self::NOT_FOUND_MINUTES));

            return null;
        }

        $this->store($feature);

        return $feature;
    }

    /** A stored boundary: by Geoapify id, else one whose polygon contains the point (the named city first). */
    private function stored(?string $geoapifyId, ?float $lat, ?float $lon, ?string $name): ?CityBoundary
    {
        if ($geoapifyId !== null && $geoapifyId !== '') {
            $byId = CityBoundary::query()->where('geoapify_id', $geoapifyId)->first();
            if ($byId !== null) {
                return $byId;
            }
        }
        if ($lat === null || $lon === null) {
            return null;
        }

        $containing = CityBoundary::query()
            ->where('min_lat', '<=', $lat)->where('max_lat', '>=', $lat)
            ->where('min_lon', '<=', $lon)->where('max_lon', '>=', $lon)
            ->get()
            ->filter(fn (CityBoundary $boundary): bool => self::contains($boundary->feature['geometry'] ?? null, $lon, $lat))
            // The smallest area first: a city before a larger unit that also contains the point.
            ->sortBy(fn (CityBoundary $boundary): float => ($boundary->max_lat - $boundary->min_lat) * ($boundary->max_lon - $boundary->min_lon))
            ->values();

        // A named city (from the search) only matches a boundary of that name: a larger unit that
        // happens to contain the point (e.g. the county) is not that city, so it is fetched instead.
        $wanted = mb_strtolower(trim((string) $name));
        if ($wanted !== '') {
            return $containing->first(fn (CityBoundary $boundary): bool => in_array(
                $wanted,
                [mb_strtolower(trim($boundary->name)), mb_strtolower(trim((string) $boundary->city))],
                true
            ));
        }

        return $containing->first();
    }

    /**
     * Whether a GeoJSON Polygon/MultiPolygon contains the point (outer ring, outside its holes).
     *
     * @param  array<string, mixed>|null  $geometry
     */
    public static function contains(?array $geometry, float $lon, float $lat): bool
    {
        $polygons = match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default => [],
        };

        foreach ($polygons as $rings) {
            if (! is_array($rings) || ! isset($rings[0]) || ! self::inRing($lon, $lat, $rings[0])) {
                continue;
            }
            $inHole = false;
            foreach (array_slice($rings, 1) as $hole) {
                if (self::inRing($lon, $lat, $hole)) {
                    $inHole = true;
                    break;
                }
            }
            if (! $inHole) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<array{0: float, 1: float}>  $ring */
    private static function inRing(float $lon, float $lat, array $ring): bool
    {
        $inside = false;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $lat) !== ($yj > $lat))
                && ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: PHP_FLOAT_EPSILON) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /** @param  array<string, mixed>  $feature */
    private function store(array $feature): void
    {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        [$minLat, $maxLat, $minLon, $maxLon] = self::bounds($feature['geometry'] ?? []);
        $values = [
            'name' => (string) ($properties['name'] ?? $properties['city'] ?? ''),
            'city' => isset($properties['city']) ? (string) $properties['city'] : null,
            'country_code' => isset($properties['country_code']) ? strtolower(substr((string) $properties['country_code'], 0, 2)) : null,
            'feature' => $feature,
            'min_lat' => $minLat,
            'max_lat' => $maxLat,
            'min_lon' => $minLon,
            'max_lon' => $maxLon,
            'fetched_at' => now(),
        ];

        $placeId = isset($properties['place_id']) ? (string) $properties['place_id'] : null;
        if ($placeId !== null && $placeId !== '') {
            CityBoundary::query()->updateOrCreate(['geoapify_id' => $placeId], $values);
        } else {
            CityBoundary::query()->create($values);
        }
    }

    /**
     * [min lat, max lat, min lon, max lon] of every position of a geometry.
     *
     * @param  array<string, mixed>  $geometry
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function bounds(array $geometry): array
    {
        $lats = [];
        $lons = [];
        // Positions are [lon, lat] or [lon, lat, elevation], nested in rings and polygons.
        $walk = function (mixed $node) use (&$walk, &$lats, &$lons): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node[0], $node[1]) && is_numeric($node[0]) && is_numeric($node[1])) {
                $lons[] = (float) $node[0];
                $lats[] = (float) $node[1];

                return;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($geometry['coordinates'] ?? []);

        if ($lats === []) {
            throw new RuntimeException('The city boundary has no coordinates.');
        }

        return [min($lats), max($lats), min($lons), max($lons)];
    }

    /**
     * The city boundary from Geoapify: the named city first, else the feature whose name is its city,
     * else Geoapify's city-level category.
     *
     * @return array<string, mixed>|null
     */
    private function fetch(?string $geoapifyId, ?float $lat, ?float $lon, ?string $name): ?array
    {
        $baseParams = [
            'boundaries' => 'administrative',
            'geometry' => 'geometry_1000',
            'lang' => substr((string) app()->getLocale(), 0, 2),
            'apiKey' => config('services.geoapify.key'),
        ];

        $queries = [];
        if ($geoapifyId !== null && $geoapifyId !== '') {
            $queries[] = [...$baseParams, 'id' => $geoapifyId];
        }
        if ($lat !== null && $lon !== null) {
            $queries[] = [...$baseParams, 'lat' => $lat, 'lon' => $lon];
        }

        $reached = false;
        foreach ($queries as $params) {
            $response = Http::timeout(15)->retry(1, 250, throw: false)->acceptJson()
                ->get('https://api.geoapify.com/v1/boundaries/part-of', $params);
            if (! $response->successful()) {
                continue;
            }
            $reached = true;

            $features = $response->json('features', []);
            $polygonFeatures = array_values(array_filter(is_array($features) ? $features : [], static fn (mixed $item): bool =>
                is_array($item) && in_array($item['geometry']['type'] ?? null, ['Polygon', 'MultiPolygon'], true)
            ));
            $requestedName = mb_strtolower(trim((string) $name));
            if ($requestedName !== '') {
                $cityBoundary = collect($polygonFeatures)->first(static function (array $item) use ($requestedName): bool {
                    $properties = $item['properties'] ?? [];

                    return in_array($requestedName, array_map(
                        static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
                        [$properties['name'] ?? '', $properties['city'] ?? '']
                    ), true);
                });

                if ($cityBoundary !== null) {
                    return $cityBoundary;
                }
            }

            $cityBoundary = collect($polygonFeatures)->first(static function (array $item): bool {
                $properties = $item['properties'] ?? [];

                return isset($properties['name'], $properties['city'])
                    && mb_strtolower(trim((string) $properties['name'])) === mb_strtolower(trim((string) $properties['city']));
            });

            if ($cityBoundary !== null) {
                return $cityBoundary;
            }

            // Some cities, including municipality-style capitals, don't
            // populate the `city` field on their boundary feature. Use
            // Geoapify's explicit city-level category as a safe fallback.
            $cityBoundary = collect($polygonFeatures)->first(static fn (array $item): bool =>
                in_array('administrative.city_level', $item['properties']['categories'] ?? [], true)
            );

            if ($cityBoundary !== null) {
                return $cityBoundary;
            }
        }

        if (! $reached) {
            throw new RuntimeException('Geoapify boundaries could not be reached.');
        }

        return null;
    }
}
