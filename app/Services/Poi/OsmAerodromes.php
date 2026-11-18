<?php

namespace App\Services\Poi;

use App\Support\GeoDistance;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Airports, airfields and terminals from OpenStreetMap, worldwide (Geoapify misses many small
 * airfields, e.g. Prejmer near Brașov). Aerodromes rarely change, so one list per area is kept
 * for a week: Overpass first, Nominatim when every Overpass server is busy.
 *
 * @phpstan-type Aerodrome array{id: string, name: ?string, lat: float, lon: float, subcategory: string, tags: array<string, string>}
 */
class OsmAerodromes
{
    /** Every search (max. 35 km) around a grid cell of 0.1° fits in this area. */
    public const AREA_RADIUS_METERS = 50000;

    /** Overpass (3 s) plus the Nominatim fallback (FALLBACK_SECONDS). */
    public const BUDGET_SECONDS = 6;

    /** Kept for Nominatim when the main Overpass server does not answer. */
    public const FALLBACK_SECONDS = 3;

    /** Geoapify subcategories of the airport category, which OSM aerodromes are sorted into. */
    public const SUBCATEGORIES = ['airport.airfield', 'airport.gliding', 'airport.international', 'airport.military', 'airport.private', 'airport.terminal'];

    /**
     * Aerodromes within the radius, or null when OSM could not be reached (unknown).
     *
     * @return list<Aerodrome>|null
     */
    public function around(float $lat, float $lon, int $radius, int $budgetSeconds = self::BUDGET_SECONDS): ?array
    {
        $area = $this->cachedArea($lat, $lon);
        if ($area === null && ! Cache::has($this->failureKey($lat, $lon))) {
            $area = $this->fetch($lat, $lon, null, microtime(true), $budgetSeconds);
        }

        return $area === null ? null : $this->within($area, $lat, $lon, $radius);
    }

    /**
     * Only what is already cached: no network.
     *
     * @return list<Aerodrome>|null
     */
    public function cached(float $lat, float $lon, int $radius): ?array
    {
        $area = $this->cachedArea($lat, $lon);

        return $area === null ? null : $this->within($area, $lat, $lon, $radius);
    }

    public function needsFetch(float $lat, float $lon): bool
    {
        return $this->cachedArea($lat, $lon) === null && ! Cache::has($this->failureKey($lat, $lon));
    }

    /** The Overpass request of the area, for callers that send it in their own parallel pool. */
    public function overpassRequest(PendingRequest $request, float $lat, float $lon, int $timeout): \GuzzleHttp\Promise\PromiseInterface|Response
    {
        $endpoint = (string) (config('osm.overpass_endpoints')[0] ?? '');

        return $this->withOverpassOptions($request, $timeout)->get($endpoint, ['data' => $this->query($lat, $lon)]);
    }

    /**
     * Finishes a lookup whose first Overpass request was sent by the caller: parses it, or tries the
     * other sources while time is left, then caches the area.
     *
     * @return list<Aerodrome>|null
     */
    public function complete(mixed $firstResponse, float $lat, float $lon, int $radius, float $started, int $budgetSeconds = self::BUDGET_SECONDS): ?array
    {
        $area = $this->fetch($lat, $lon, $firstResponse, $started, $budgetSeconds);

        return $area === null ? null : $this->within($area, $lat, $lon, $radius);
    }

    /**
     * Which airport subcategory an OSM aerodrome belongs to, from its tags.
     *
     * @param  array<string, string>  $tags
     */
    public static function classify(array $tags): string
    {
        $type = strtolower($tags['aerodrome:type'] ?? $tags['aerodrome'] ?? '');

        return match (true) {
            ($tags['aeroway'] ?? null) === 'terminal' => 'airport.terminal',
            isset($tags['military']) || $type === 'military' || ($tags['landuse'] ?? null) === 'military' => 'airport.military',
            $type === 'gliding' || ($tags['sport'] ?? null) === 'gliding' => 'airport.gliding',
            $type === 'international' || isset($tags['iata']) => 'airport.international',
            $type === 'private' || in_array($tags['access'] ?? null, ['private', 'no'], true) => 'airport.private',
            default => 'airport.airfield',
        };
    }

    /** @return list<Aerodrome>|null */
    private function cachedArea(float $lat, float $lon): ?array
    {
        $area = Cache::get($this->areaKey($lat, $lon));

        return is_array($area) ? $area : null;
    }

    /** @return list<Aerodrome>|null */
    private function fetch(float $lat, float $lon, mixed $firstResponse, float $started, int $budgetSeconds): ?array
    {
        [$centerLat, $centerLon] = $this->areaCenter($lat, $lon);
        $endpoints = array_values(array_filter((array) config('osm.overpass_endpoints'), 'is_string'));
        $overpass = function (string $endpoint, int $timeout) use ($lat, $lon): ?array {
            try {
                return $this->fromOverpass($this->withOverpassOptions(Http::acceptJson(), $timeout)->get($endpoint, ['data' => $this->query($lat, $lon)]));
            } catch (\Throwable) {
                return null;
            }
        };

        // 1. The main Overpass server (possibly already asked by the caller, in its parallel batch).
        $area = $firstResponse !== null
            ? $this->fromOverpass($firstResponse)
            : (isset($endpoints[0]) ? $overpass($endpoints[0], max(2, $this->secondsLeft($started, $budgetSeconds) - self::FALLBACK_SECONDS)) : null);
        $complete = $area !== null;

        // 2. Nominatim answers fast; the other public Overpass servers are often the slow ones.
        $left = $this->secondsLeft($started, $budgetSeconds);
        if ($area === null && $left >= 1) {
            try {
                $area = $this->fromNominatim($centerLat, $centerLon, $left);
            } catch (\Throwable) {
                // Next source.
            }
        }

        // 3. The other Overpass servers, with the time left.
        foreach (array_slice($endpoints, 1) as $endpoint) {
            $left = $this->secondsLeft($started, $budgetSeconds);
            if ($area !== null || $left < 2) {
                break;
            }
            $area = $overpass($endpoint, $left);
            $complete = $area !== null;
        }

        if ($area === null) {
            // Do not ask again on every request while the servers are busy.
            Cache::put($this->failureKey($lat, $lon), true, now()->addMinutes(2));

            return null;
        }

        // Nominatim lists at most 40 aerodromes and no terminals: Overpass is asked again sooner.
        Cache::put($this->areaKey($lat, $lon), $area, $complete ? now()->addDays(7) : now()->addDay());

        return $area;
    }

    private function withOverpassOptions(PendingRequest $request, int $timeout): PendingRequest
    {
        return $request->withHeaders(['User-Agent' => 'Symbiot/1.0 aerodromes'])
            ->connectTimeout(min(3, $timeout))
            ->timeout($timeout)
            ->acceptJson();
    }

    private function query(float $lat, float $lon): string
    {
        [$centerLat, $centerLon] = $this->areaCenter($lat, $lon);
        $around = sprintf('(around:%d,%F,%F)', self::AREA_RADIUS_METERS, $centerLat, $centerLon);

        return '[out:json][timeout:' . self::BUDGET_SECONDS . '];(nwr["aeroway"="aerodrome"]' . $around . ';nwr["aeroway"="terminal"]' . $around . ';);out center tags;';
    }

    /** @return list<Aerodrome>|null */
    private function fromOverpass(mixed $response): ?array
    {
        if (! $response instanceof Response || ! $response->successful() || ! is_array($elements = $response->json('elements'))) {
            return null;
        }

        $area = [];
        foreach ($elements as $element) {
            $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
            $lon = $element['lon'] ?? $element['center']['lon'] ?? null;
            $tags = is_array($element['tags'] ?? null) ? array_map('strval', $element['tags']) : [];
            if (! is_numeric($lat) || ! is_numeric($lon) || ! in_array($tags['aeroway'] ?? null, ['aerodrome', 'terminal'], true)) {
                continue;
            }
            $area[] = $this->aerodrome(($element['type'] ?? 'node') . '/' . ($element['id'] ?? 0), (float) $lat, (float) $lon, $tags);
        }

        return $area;
    }

    /**
     * Nominatim has no "everything of a kind in an area" query, but the "aerodrome" special phrase
     * inside a bounded box finds the aerodromes there (at most 40, terminals not included).
     *
     * @return list<Aerodrome>|null
     */
    private function fromNominatim(float $lat, float $lon, int $timeout): ?array
    {
        $latPad = self::AREA_RADIUS_METERS / 111320;
        $lonPad = self::AREA_RADIUS_METERS / (111320 * max(0.01, cos(deg2rad($lat))));
        $response = Http::withHeaders(['User-Agent' => 'Symbiot/1.0 aerodromes'])
            ->connectTimeout(min(3, $timeout))
            ->timeout($timeout)
            ->acceptJson()
            ->get(rtrim((string) config('osm.nominatim_url'), '/') . '/search', [
                'q' => 'aerodrome',
                'format' => 'jsonv2',
                'limit' => 40,
                'extratags' => 1,
                'bounded' => 1,
                'viewbox' => sprintf('%F,%F,%F,%F', $lon - $lonPad, $lat + $latPad, $lon + $lonPad, $lat - $latPad),
            ]);
        $places = $response->json();
        if (! $response->successful() || ! is_array($places) || ! array_is_list($places)) {
            return null;
        }

        $area = [];
        foreach ($places as $place) {
            if (! is_array($place) || ($place['category'] ?? null) !== 'aeroway' || ($place['type'] ?? null) !== 'aerodrome'
                || ! is_numeric($place['lat'] ?? null) || ! is_numeric($place['lon'] ?? null)) {
                continue;
            }
            $tags = ['aeroway' => 'aerodrome']
                + (is_array($place['extratags'] ?? null) ? array_map('strval', array_filter($place['extratags'], 'is_scalar')) : [])
                + (isset($place['name']) && $place['name'] !== '' ? ['name' => (string) $place['name']] : []);
            $area[] = $this->aerodrome(($place['osm_type'] ?? 'node') . '/' . ($place['osm_id'] ?? 0), (float) $place['lat'], (float) $place['lon'], $tags);
        }

        return $area;
    }

    /**
     * @param  array<string, string>  $tags
     * @return Aerodrome
     */
    private function aerodrome(string $id, float $lat, float $lon, array $tags): array
    {
        $name = trim($tags['name'] ?? '');

        return ['id' => $id, 'name' => $name !== '' ? $name : null, 'lat' => $lat, 'lon' => $lon, 'subcategory' => self::classify($tags), 'tags' => $tags];
    }

    /**
     * @param  list<Aerodrome>  $area
     * @return list<Aerodrome>
     */
    private function within(array $area, float $lat, float $lon, int $radius): array
    {
        return array_values(array_filter($area, fn (array $a): bool => GeoDistance::meters($lat, $lon, $a['lat'], $a['lon']) <= $radius));
    }

    /** @return array{float, float} */
    private function areaCenter(float $lat, float $lon): array
    {
        return [round($lat, 1), round($lon, 1)];
    }

    private function areaKey(float $lat, float $lon): string
    {
        [$centerLat, $centerLon] = $this->areaCenter($lat, $lon);

        return sprintf('osm_aerodromes:v1:%.1f:%.1f', $centerLat, $centerLon);
    }

    private function failureKey(float $lat, float $lon): string
    {
        return $this->areaKey($lat, $lon) . ':failed';
    }

    private function secondsLeft(float $started, int $budgetSeconds): int
    {
        return (int) floor($budgetSeconds - (microtime(true) - $started));
    }
}
