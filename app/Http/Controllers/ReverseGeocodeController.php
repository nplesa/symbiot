<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReverseGeocodeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $apiKey = config('services.geoapify.key');

        if (empty($apiKey)) {
            return response()->json([
                'message' => 'Geoapify API key is not configured.',
            ], 503);
        }

        $latitude = (float) $validated['lat'];
        $longitude = (float) $validated['lon'];

        $cacheKey = 'geoapify.reverse.' .
            number_format($latitude, 5, '.', '') . '.' .
            number_format($longitude, 5, '.', '');

        try {
            $address = Cache::remember($cacheKey, now()->addDay(), function () use (
                $latitude,
                $longitude,
                $apiKey
            ) {
                $response = Http::timeout(10)
                    ->retry(2, 300)
                    ->get('https://api.geoapify.com/v1/geocode/reverse', [
                        'lat' => $latitude,
                        'lon' => $longitude,
                        'format' => 'json',
                        'lang' => 'ro',
                        'limit' => 1,
                        'apiKey' => $apiKey,
                    ]);

                $response->throw();

                $result = $response->json('results.0');

                if (!$result) {
                    return null;
                }

                return [
                    'full_address' => $result['formatted'] ?? null,
                    'street' => $result['street'] ?? null,
                    'house_number' => $result['housenumber'] ?? null,
                    'district' => $result['suburb']
                        ?? $result['district']
                        ?? $result['neighbourhood']
                        ?? null,
                    'city' => $result['city']
                        ?? $result['town']
                        ?? $result['village']
                        ?? $result['municipality']
                        ?? null,
                    'county' => $result['county']
                        ?? $result['state']
                        ?? null,
                    'postcode' => $result['postcode'] ?? null,
                    'country' => $result['country'] ?? null,
                ];
            });

            if (!$address) {
                return response()->json([
                    'message' => 'No address was found for these coordinates.',
                    'address' => null,
                ]);
            }

            return response()->json([
                'message' => 'Address retrieved successfully.',
                'address' => $address,
            ]);

        } catch (Throwable $exception) {
            Log::error('Geoapify reverse geocoding failed.', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to retrieve the address at this time.',
            ], 502);
        }
    }
}
