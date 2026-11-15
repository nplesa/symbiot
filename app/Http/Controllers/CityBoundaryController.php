<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CityBoundaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required_without:id', 'numeric', 'between:-90,90'],
            'lon' => ['required_without:id', 'numeric', 'between:-180,180'],
            'id' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);
        $apiKey = config('services.geoapify.key');
        if (blank($apiKey)) {
            return response()->json(['error' => 'Geoapify nu este configurat.'], 503);
        }

        $cacheKey = 'city-boundary:'.sha1($validated['id'] ?? ($validated['lat'].','.$validated['lon']));

        try {
            $feature = Cache::remember($cacheKey, now()->addDays(30), function () use ($validated, $apiKey): ?array {
                $baseParams = [
                    'boundaries' => 'administrative',
                    'geometry' => 'geometry_1000',
                    'lang' => substr((string) app()->getLocale(), 0, 2),
                    'apiKey' => $apiKey,
                ];

                $queries = [];
                if (! empty($validated['id'])) {
                    $queries[] = [...$baseParams, 'id' => $validated['id']];
                }
                $queries[] = [...$baseParams, 'lat' => $validated['lat'], 'lon' => $validated['lon']];

                foreach ($queries as $params) {
                    $response = Http::timeout(15)->retry(1, 250)->acceptJson()
                        ->get('https://api.geoapify.com/v1/boundaries/part-of', $params);
                    if (! $response->successful()) {
                        continue;
                    }

                    $features = $response->json('features', []);
                    $polygonFeatures = array_values(array_filter($features, static fn (array $item): bool =>
                        in_array($item['geometry']['type'] ?? null, ['Polygon', 'MultiPolygon'], true)
                    ));
                    $requestedName = mb_strtolower(trim((string) ($validated['name'] ?? '')));
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

                return null;
            });

            if (! is_array($feature)) {
                return response()->json(['error' => 'Nu am putut identifica limita administrativă a orașului.'], 404);
            }

            return response()->json(['feature' => $feature]);
        } catch (\Throwable) {
            return response()->json(['error' => 'Limita orașului nu este disponibilă momentan.'], 502);
        }
    }
}
