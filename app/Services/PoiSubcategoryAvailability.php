<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Tells which subcategories of every POI category actually have places around a location. */
class PoiSubcategoryAvailability
{
    public function __construct(private PoiCatalog $catalog, private FuelBrandAvailability $fuelBrands) {}

    /**
     * @return array<string, list<string>> Subcategory ids per category. Categories whose availability is unknown are omitted.
     */
    public function around(float $lat, float $lon, int $radius): array
    {
        $key = sprintf('poi_subcategories:v3:%.2f:%.2f:%d', $lat, $lon, intdiv($radius, 500));

        return Cache::remember($key, now()->addHours(6), fn (): array => $this->compute($lat, $lon, $radius));
    }

    /** @return array<string, list<string>> */
    private function compute(float $lat, float $lon, int $radius): array
    {
        $result = [];
        $geoapifyTypes = [];
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

        foreach ($this->geoapifyAvailability($geoapifyTypes, $lat, $lon, $radius) as $type => $found) {
            $subcategories = $this->catalog->subcategories($type);
            $matched = $this->matchGeoapify($subcategories, $found);
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

        return $result;
    }

    /** @param list<array<string, mixed>> $subcategories @return list<string> */
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

        return DB::table('transport_points')
            ->where('type', $type)
            ->whereBetween('lat', [$lat - $latPad, $lat + $latPad])
            ->whereBetween('lon', [$lon - $lonPad, $lon + $lonPad])
            ->get(['lat', 'lon'])
            ->contains(fn ($point): bool => $this->metersBetween(
                $lat,
                $lon,
                (float) $point->lat,
                (float) $point->lon,
            ) <= $radius);
    }

    private function hasNearbyGtfsSubwayStops(float $lat, float $lon, int $radius): bool
    {
        $latPad = $radius / 111320;
        $lonPad = $radius / (111320 * max(0.01, cos(deg2rad($lat))));
        $stops = DB::table('transit_stops as s')
            ->join('transit_stop_times as st', fn ($join) => $join
                ->on('st.feed_id', '=', 's.feed_id')
                ->on('st.stop_id', '=', 's.stop_id'))
            ->join('transit_trips as t', fn ($join) => $join
                ->on('t.feed_id', '=', 'st.feed_id')
                ->on('t.trip_id', '=', 'st.trip_id'))
            ->join('transit_routes as r', fn ($join) => $join
                ->on('r.feed_id', '=', 't.feed_id')
                ->on('r.route_id', '=', 't.route_id'))
            ->join('transit_feeds as f', 'f.id', '=', 's.feed_id')
            ->where('f.active', true)
            ->whereNotNull('f.static_hash')
            ->where('r.route_type', 1)
            ->whereBetween('s.lat', [$lat - $latPad, $lat + $latPad])
            ->whereBetween('s.lon', [$lon - $lonPad, $lon + $lonPad])
            ->distinct()
            ->get(['s.lat', 's.lon']);

        return $stops->contains(fn ($stop): bool => $this->metersBetween(
            $lat,
            $lon,
            (float) $stop->lat,
            (float) $stop->lon,
        ) <= $radius);
    }

    private function metersBetween(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371000 * 2 * asin(min(1, sqrt($a)));
    }

    /**
     * @param  list<string>  $types
     * @return array<string, list<string>> Geoapify category names found per POI type.
     */
    private function geoapifyAvailability(array $types, float $lat, float $lon, int $radius): array
    {
        if ($types === [] || ! config('services.geoapify.key')) {
            return [];
        }

        $responses = Http::pool(fn (Pool $pool) => collect($types)
            ->map(fn (string $type) => $pool->as($type)->timeout(15)->acceptJson()->get('https://api.geoapify.com/v2/places', [
                'categories' => implode(',', $this->catalog->categories()[$type]['geoapify']),
                'filter' => sprintf('circle:%F,%F,%d', $lon, $lat, $radius),
                'limit' => 500,
                'apiKey' => config('services.geoapify.key'),
            ]))
            ->all());

        $found = [];
        foreach ($types as $type) {
            $response = $responses[$type] ?? null;
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }

            $found[$type] = collect($response->json('features') ?? [])
                ->flatMap(fn (array $feature): array => $feature['properties']['categories'] ?? [])
                ->unique()
                ->values()
                ->all();
        }

        return $found;
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
