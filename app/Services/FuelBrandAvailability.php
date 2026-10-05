<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Tells which fuel brand subcategories actually have stations around a location. */
class FuelBrandAvailability
{
    /**
     * @return ?list<string> Subcategory ids (without the "subcategory:fuel:" prefix), or null when unknown.
     */
    public function around(float $lat, float $lon, int $radius): ?array
    {
        $key = sprintf('fuel_brands:v1:%.2f:%.2f:%d', $lat, $lon, intdiv($radius, 500));

        return Cache::remember($key, now()->addHours(6), function () use ($lat, $lon, $radius): ?array {
            $tagSets = $this->localTags($lat, $lon, $radius);

            if ($tagSets === []) {
                $tagSets = $this->overpassTags($lat, $lon, $radius);
            }

            return $tagSets === null ? null : $this->matchBrands($tagSets);
        });
    }

    /** @param list<array<string, string>> $tagSets @return list<string> */
    private function matchBrands(array $tagSets): array
    {
        $available = ['all'];

        foreach (config('poi.osm_subcategories.fuel', []) as $subcategory) {
            $id = $subcategory['id'];
            if ($id === 'all') {
                continue;
            }

            $selector = $subcategory['osm'][0] ?? [];
            $names = isset($selector['brand']) ? explode('|', $selector['brand']) : [];

            foreach ($tagSets as $tags) {
                $matches = $names === []
                    ? ($tags['fuel:lpg'] ?? null) === 'yes'
                    : (in_array($tags['brand'] ?? null, $names, true)
                        || in_array($tags['operator'] ?? null, $names, true)
                        || in_array($tags['name'] ?? null, $names, true));

                if ($matches) {
                    $available[] = $id;
                    break;
                }
            }
        }

        return $available;
    }

    /** @return list<array<string, string>> */
    private function localTags(float $lat, float $lon, int $radius): array
    {
        $latPad = $radius / 111320;
        $lonPad = $radius / (111320 * max(0.01, cos(deg2rad($lat))));

        return DB::table('transport_points')
            ->where('type', 'fuel')
            ->whereBetween('lat', [$lat - $latPad, $lat + $latPad])
            ->whereBetween('lon', [$lon - $lonPad, $lon + $lonPad])
            ->get(['tags', 'lat', 'lon'])
            ->filter(fn ($row): bool => $this->meters($lat, $lon, (float) $row->lat, (float) $row->lon) <= $radius)
            ->map(fn ($row): array => json_decode((string) $row->tags, true) ?: [])
            ->values()
            ->all();
    }

    /** @return ?list<array<string, string>> */
    private function overpassTags(float $lat, float $lon, int $radius): ?array
    {
        $response = Http::withHeaders(['User-Agent' => 'Symbiot/1.0 fuel brands'])
            ->timeout(25)
            ->get('https://overpass-api.de/api/interpreter', [
                'data' => sprintf('[out:json][timeout:25];nwr["amenity"="fuel"](around:%d,%F,%F);out tags;', $radius, $lat, $lon),
            ]);

        if (! $response->successful()) {
            return null;
        }

        return collect($response->json('elements') ?? [])->map(fn (array $e): array => $e['tags'] ?? [])->all();
    }

    private function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;

        return 6371000 * 2 * asin(min(1, sqrt($a)));
    }
}
