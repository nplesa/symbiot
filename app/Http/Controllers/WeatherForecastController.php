<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WeatherForecastController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $apiKey = config('services.weather_api.key');
        if (blank($apiKey)) {
            return response()->json(['message' => 'Weather forecast is not configured.'], 503);
        }

        $latitude = (float) $validated['lat'];
        $longitude = (float) $validated['lon'];
        $cacheKey = 'weatherapi.forecast.' . number_format($latitude, 3, '.', '') . '.' . number_format($longitude, 3, '.', '');

        try {
            $forecast = Cache::get($cacheKey);
            if (! is_array($forecast)) {
                $baseUrl = rtrim((string) config('services.weather_api.base_url'), '/');
                $response = Http::acceptJson()
                    ->connectTimeout(4)
                    ->timeout(10)
                    ->retry(1, 250)
                    ->get($baseUrl . '/forecast.json', [
                        'key' => $apiKey,
                        'q' => $latitude . ',' . $longitude,
                        'days' => 3,
                        'aqi' => 'no',
                        'alerts' => 'no',
                    ]);

                if (! $response->successful()) {
                    return response()->json(['message' => 'Weather forecast is unavailable right now.'], 502);
                }

                $payload = $response->json();
                $current = $payload['current'] ?? [];
                $condition = $current['condition'] ?? [];
                $days = array_slice($payload['forecast']['forecastday'] ?? [], 0, 3);

                $forecast = [
                    'current' => [
                        'temperature_c' => $current['temp_c'] ?? null,
                        'feels_like_c' => $current['feelslike_c'] ?? null,
                        'condition' => $condition['text'] ?? null,
                        'icon' => $condition['icon'] ?? null,
                    ],
                    'days' => array_map(static function (array $day): array {
                        $summary = $day['day'] ?? [];
                        $condition = $summary['condition'] ?? [];

                        return [
                            'date' => $day['date'] ?? null,
                            'min_c' => $summary['mintemp_c'] ?? null,
                            'max_c' => $summary['maxtemp_c'] ?? null,
                            'condition' => $condition['text'] ?? null,
                            'icon' => $condition['icon'] ?? null,
                            'rain_chance' => $summary['daily_chance_of_rain'] ?? null,
                        ];
                    }, $days),
                ];

                Cache::put($cacheKey, $forecast, now()->addMinutes(15));
            }

            return response()->json($forecast);
        } catch (\Throwable) {
            return response()->json(['message' => 'Weather forecast is unavailable right now.'], 502);
        }
    }
}
