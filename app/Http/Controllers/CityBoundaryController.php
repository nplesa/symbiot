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
                    'lang' => 'en',
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
                    foreach ($features as $item) {
                        $properties = $item['properties'] ?? [];
                        if (($properties['name'] ?? null) === ($properties['city'] ?? null)
                            && in_array($item['geometry']['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
                            return $item;
                        }
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
