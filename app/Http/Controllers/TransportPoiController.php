<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TransportPoiController extends Controller
{
    public function nearby(Request $request)
    {
        $validated = $request->validate([
            'lat'    => 'required|numeric|between:-90,90',
            'lon'    => 'required|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:100|max:35000',
            'types'  => 'nullable|string',
        ]);

        $lat = (float) $validated['lat'];
        $lon = (float) $validated['lon'];
        $radius = (int) ($validated['radius'] ?? 5000);

        $categories = $this->buildCategories($request);

        $cacheKey = sprintf(
            'transport_poi:%s:%s:%s:%s',
            round($lat, 4),
            round($lon, 4),
            $radius,
            md5($categories)
        );

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($lat, $lon, $radius, $categories) {
                $features = $this->fetchFromGeoapify($lat, $lon, $radius, $categories);
                return ['features' => $features];
            });

            $result = $this->transform($data['features'] ?? [], $lat, $lon);

            return response()->json($result);

        } catch (\Throwable $e) {
            Log::error('TransportPoiController error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * =====================================================
     * GEOAPIFY FETCH (handles >500 limit)
     * =====================================================
     */
    private function fetchFromGeoapify(float $lat, float $lon, int $radius, string $categories): array
    {
        $points = $this->buildGridPoints($lat, $lon);

        $all = [];

        foreach ($points as [$pLat, $pLon]) {

            try {
                $response = Http::timeout(10)
                    ->retry(1, 200)
                    ->acceptJson()
                    ->get('https://api.geoapify.com/v2/places', [
                        'categories' => $categories,
                        'filter'     => "circle:$pLon,$pLat,$radius",
                        'limit'      => 500,
                        'apiKey'     => config('services.geoapify.key'),
                    ]);

                if ($response->successful()) {
                    $features = $response->json('features') ?? [];
                    $all = array_merge($all, $features);
                }

            } catch (\Throwable $e) {
                Log::warning('Geoapify partial failure', [
                    'message' => $e->getMessage()
                ]);
            }
        }

        return $this->deduplicate($all);
    }

    /**
     * =====================================================
     * GRID (fixes 500 limit issue)
     * =====================================================
     */
    private function buildGridPoints(float $lat, float $lon): array
    {
        $delta = 0.25; // ~20-25km

        return [
            [$lat, $lon],

            [$lat + $delta, $lon],
            [$lat - $delta, $lon],
            [$lat, $lon + $delta],
            [$lat, $lon - $delta],
        ];
    }

    /**
     * =====================================================
     * DEDUPLICATION
     * =====================================================
     */
    private function deduplicate(array $features): array
    {
        return collect($features)
            ->unique(fn ($f) => $f['properties']['place_id'] ?? null)
            ->values()
            ->all();
    }

    /**
     * =====================================================
     * CATEGORIES
     * =====================================================
     */
    private function buildCategories(Request $request): string
    {
        $map = [
            'airport' => ['airport', 'airport.airfield'],
            'bus' => ['public_transport.bus'],
            'train' => ['public_transport.train'],
            'subway' => ['public_transport.subway'],
            'taxi' => ['service.taxi'],
            'hospital' => ['healthcare.hospital'],
            'pharmacy' => ['healthcare.pharmacy'],
            'police' => ['service.police'],
            'fire' => ['service.fire_station'],
            'tourism' => [
                'tourism',
                'tourism.sights',
                'tourism.attraction',
                'heritage',
            ],
        ];

        $types = $request->filled('types')
            ? collect(explode(',', $request->string('types')))
                ->map(fn ($t) => trim($t))
                ->filter()
                ->values()
                ->all()
            : (json_decode(config('services.geoapify.locations', '[]'), true) ?? []);

        $categories = collect($types)
            ->filter(fn ($t) => isset($map[$t]))
            ->flatMap(fn ($t) => $map[$t])
            ->unique()
            ->values();

        return $categories->isEmpty()
            ? 'public_transport'
            : $categories->implode(',');
    }

    /**
     * =====================================================
     * TRANSFORM
     * =====================================================
     */
    private function transform(array $features, float $userLat, float $userLon): array
    {
        $out = [];

        foreach ($features as $feature) {

            $props = $feature['properties'] ?? [];
            $coords = $feature['geometry']['coordinates'] ?? null;

            if (!is_array($coords) || count($coords) !== 2) {
                continue;
            }

            [$lon, $lat] = $coords;

            $meters = $this->distance($userLat, $userLon, $lat, $lon);

            $out[] = [
                'id' => $props['place_id'] ?? null,

                'name' =>
                    $props['name']
                    ?? $props['formatted']
                    ?? $props['street']
                    ?? 'Unknown',
    
                'type' => $this->detectType($props['categories'] ?? []),

                'coordinates' => [
                    'lat' => $lat,
                    'lon' => $lon,
                ],

                'distance' => [
                    'meters' => (int) round($meters),
                    'km' => round($meters / 1000, 2),
                    'formatted' => $meters < 1000
                        ? round($meters) . ' m'
                        : round($meters / 1000, 1) . ' km',
                ],

                'address' => [
                    'formatted' => $props['formatted'] ?? null,
                    'city'      => $props['city'] ?? null,
                    'country'   => $props['country'] ?? null,
                ],
            ];
        }

        usort($out, fn ($a, $b) =>
            $a['distance']['meters'] <=> $b['distance']['meters']
        );

        return $out;
    }

    /**
     * =====================================================
     * TYPE DETECTION
     * =====================================================
     */
    private function detectType(array $categories): string
    {
        foreach ($categories as $c) {

            if (str_starts_with($c, 'public_transport.bus')) return 'bus';
            if (str_starts_with($c, 'public_transport.train')) return 'train';
            if (str_starts_with($c, 'public_transport.subway')) return 'subway';
            if (str_starts_with($c, 'airport')) return 'airport';
            if (str_starts_with($c, 'tourism')) return 'tourism';
            if (str_starts_with($c, 'healthcare.hospital')) return 'hospital';
            if (str_starts_with($c, 'healthcare.pharmacy')) return 'pharmacy';
            if (str_starts_with($c, 'service.fire_station')) return 'fire';
            if (str_starts_with($c, 'service.taxi')) return 'taxi';
            if (str_starts_with($c, 'service.police')) return 'police';
        }

        return 'transport';
    }

    /**
     * =====================================================
     * HAVERSINE
     * =====================================================
     */
    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2 +
            cos(deg2rad($lat1)) *
            cos(deg2rad($lat2)) *
            sin($dLon / 2) ** 2;

        return $R * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}