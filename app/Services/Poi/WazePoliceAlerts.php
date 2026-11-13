<?php

namespace App\Services\Poi;

use App\Support\GeoDistance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Police alerts reported in Waze (OpenWebNinja API), as POIs around a point. */
class WazePoliceAlerts
{
    /**
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException when the API is not configured or fails
     */
    public function nearby(float $lat, float $lon, int $radius): array
    {
        $cacheKey = sprintf(
            'waze_police_alerts:v1:%s:%s:%s',
            round($lat, 4),
            round($lon, 4),
            $radius
        );

        $alerts = Cache::remember(
            $cacheKey,
            now()->addMinutes(10),
            fn (): array => $this->fetch($lat, $lon, $radius)
        );

        return $this->transform($alerts, $lat, $lon, $radius);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(float $lat, float $lon, int $radius): array
    {
        $baseUrl = config('services.waze.url');
        $apiKey = config('services.waze.key');
        if (! is_string($baseUrl) || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('WAZE_API_URL nu este configurat cu un URL valid.');
        }
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new \RuntimeException('WAZE_API_KEY nu este configurată. Adaugă cheia în .env și reîncarcă configurația aplicației.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'User-Agent' => 'Symbiot/1.0 nearby Waze traffic alerts',
        ])->timeout(20)
            ->acceptJson()
            ->get(rtrim($baseUrl, '/') . '/alerts-and-jams', [
                'center' => $lat . ',' . $lon,
                'radius' => round($radius / 1000, 2),
                'radius_units' => 'KM',
            ]);

        if (! $response->successful()) {
            $status = $response->status();
            $message = match ($status) {
                401, 403 => "OpenWebNinja Waze API returned HTTP {$status}. Verifică WAZE_API_KEY și confirmă că API-ul Waze este activat în contul OpenWebNinja.",
                429 => 'OpenWebNinja Waze API returned HTTP 429. Cota sau limita de cereri a fost atinsă.',
                default => "OpenWebNinja Waze API returned HTTP {$status}.",
            };

            throw new \RuntimeException($message);
        }

        $alerts = $response->json('data.alerts');
        if (! is_array($alerts)) {
            throw new \RuntimeException('OpenWebNinja Waze API returned an unexpected alerts response.');
        }

        return array_values(array_filter($alerts, static fn ($alert): bool => is_array($alert)));
    }

    /**
     * @param  list<array<string, mixed>>  $alerts
     * @return list<array<string, mixed>>
     */
    private function transform(array $alerts, float $userLat, float $userLon, int $radius): array
    {
        $pois = [];

        foreach ($alerts as $alert) {
            if (strtoupper((string) ($alert['type'] ?? '')) !== 'POLICE') {
                continue;
            }

            $lat = filter_var($alert['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($alert['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($lat === false || $lon === false || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                continue;
            }

            $meters = GeoDistance::meters($userLat, $userLon, (float) $lat, (float) $lon);
            if ($meters > $radius) {
                continue;
            }

            $street = trim((string) ($alert['street'] ?? ''));
            $city = trim((string) ($alert['city'] ?? ''));
            $description = trim((string) ($alert['description'] ?? ''));
            $subtype = trim(str_replace('_', ' ', (string) ($alert['subtype'] ?? '')));
            $id = trim((string) ($alert['alert_id'] ?? ''));
            $pois[] = [
                'id' => 'waze/' . ($id !== '' ? $id : sha1(json_encode([$lat, $lon, $alert['type'] ?? 'POLICE']))),
                'name' => $description !== '' ? $description : ($subtype !== '' ? ucfirst(strtolower($subtype)) : 'Filtru de poliție Waze'),
                'type' => 'police',
                'routes' => [],
                'routes_available' => true,
                'coordinates' => ['lat' => (float) $lat, 'lon' => (float) $lon],
                'distance' => [
                    'meters' => (int) round($meters),
                    'km' => round($meters / 1000, 2),
                    'formatted' => $meters < 1000
                        ? round($meters) . ' m'
                        : round($meters / 1000, 1) . ' km',
                ],
                'address' => [
                    'formatted' => implode(', ', array_filter([$street, $city])),
                    'city' => $city !== '' ? $city : null,
                    'country' => $alert['country'] ?? null,
                ],
                'details' => array_filter([
                    'provider' => 'Waze',
                    'reported_at' => $alert['publish_datetime_utc'] ?? null,
                ]),
            ];
        }

        return $pois;
    }
}
