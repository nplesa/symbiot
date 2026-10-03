<?php

namespace App\Http\Controllers;

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
            return response()->json(['error' => 'Geoapify nu este configurat.'], 503);
        }

        try {
            $response = Http::timeout(10)
                ->retry(1, 200)
                ->acceptJson()
                ->get('https://api.geoapify.com/v1/geocode/search', [
                    'text' => $validated['city'],
                    'limit' => 5,
                    'format' => 'json',
                    'lang' => 'ro',
                    'filter' => 'countrycode:ro',
                    'apiKey' => $apiKey,
                ]);

            if (! $response->successful()) {
                Log::warning('Geoapify city lookup failed', ['status' => $response->status()]);

                return response()->json(['error' => 'Căutarea orașului nu a reușit.'], 502);
            }

            $result = collect($response->json('results') ?? [])
                ->first(fn (array $item): bool => isset($item['lat'], $item['lon']));

            if ($result === null) {
                return response()->json(['error' => 'Orașul nu a fost găsit în România.'], 404);
            }

            return response()->json([
                'name' => $result['city']
                    ?? $result['town']
                    ?? $result['village']
                    ?? $result['name']
                    ?? $validated['city'],
                'lat' => (float) $result['lat'],
                'lon' => (float) $result['lon'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Geoapify city lookup failed', ['message' => $e->getMessage()]);

            return response()->json(['error' => 'Serviciul de căutare a orașului nu este disponibil.'], 502);
        }
    }
}
