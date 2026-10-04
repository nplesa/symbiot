<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CityLocationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'city' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $apiKey = config('services.geoapify.key');
        if (blank($apiKey)) {
            return response()->json(['error' => 'Geoapify is not configured.'], 503);
        }

        try {
            $response = Http::timeout(10)
                ->retry(1, 200)
                ->acceptJson()
                ->get('https://api.geoapify.com/v1/geocode/search', [
                    'text' => $validated['city'],
                    'limit' => 8,
                    'format' => 'json',
                    'lang' => 'en',
                    'type' => 'city',
                    'apiKey' => $apiKey,
                ]);

            if (! $response->successful()) {
                Log::warning('Geoapify city lookup failed', ['status' => $response->status()]);

                return response()->json(['error' => 'City search failed.'], 502);
            }

            $geocodedResults = $response->json('results', []);
            if (! is_array($geocodedResults)) {
                Log::warning('Geoapify city lookup returned an invalid result list');

                return response()->json(['error' => 'City search returned an invalid response.'], 502);
            }

            $results = collect($geocodedResults)
                ->filter(fn (mixed $item): bool => is_array($item)
                    && is_numeric($item['lat'] ?? null)
                    && is_numeric($item['lon'] ?? null))
                ->unique(fn (array $item): string => (string) ($item['place_id'] ?? implode(',', [
                    $item['lat'],
                    $item['lon'],
                ])))
                ->map(function (array $item) use ($validated): array {
                    $name = $item['city']
                        ?? $item['town']
                        ?? $item['village']
                        ?? $item['municipality']
                        ?? $item['name']
                        ?? $validated['city'];
                    $parts = array_values(array_unique(array_filter([
                        $name,
                        $item['state'] ?? null,
                        $item['country'] ?? null,
                    ], fn (mixed $part): bool => is_string($part) && trim($part) !== '')));

                    return [
                        'name' => $name,
                        'label' => implode(', ', $parts),
                        'country' => $item['country'] ?? null,
                        'country_code' => $item['country_code'] ?? null,
                        'lat' => (float) $item['lat'],
                        'lon' => (float) $item['lon'],
                    ];
                })
                ->values();

            if ($results->isEmpty()) {
                return response()->json(['error' => 'No matching city was found. Try adding the country name.'], 404);
            }

            return response()->json(['results' => $results]);
        } catch (ConnectionException $e) {
            Log::warning('Geoapify city lookup failed', ['message' => $e->getMessage()]);

            return response()->json(['error' => 'The city search service is unavailable.'], 502);
        }
    }
}
