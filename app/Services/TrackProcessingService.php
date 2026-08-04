<?php

namespace App\Services;

use App\Models\Tracking;
use Illuminate\Support\Collection;

class TrackProcessingService
{
    /**
     * Procesează punctele GPS.
     *
     * @param  Collection<int, Tracking>  $points
     * @return array{
     *     distance: float,
     *     geojson: array{
     *         type: string,
     *         coordinates: array<int, array{0: float, 1: float}>
     *     }
     * }
     */
    public function process(Collection $points): array
    {
        return [
            'distance' => $this->calculateDistance($points),
            'geojson' => $this->buildGeoJson($points),
        ];
    }

    /**
     * Calculează distanța totală.
     *
     * @param  Collection<int, Tracking>  $points
     */
    private function calculateDistance(Collection $points): float
    {
        $distance = 0.0;

        for ($i = 1; $i < $points->count(); $i++) {
            $distance += $this->haversine(
                (float) $points[$i - 1]->latitude,
                (float) $points[$i - 1]->longitude,
                (float) $points[$i]->latitude,
                (float) $points[$i]->longitude
            );
        }

        return $distance;
    }

    /**
     * Formula Haversine.
     */
    private function haversine(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a =
            sin($dLat / 2) ** 2 +
            cos(deg2rad($lat1)) *
            cos(deg2rad($lat2)) *
            sin($dLon / 2) ** 2;

        return 2 * $earthRadius * asin(sqrt($a));
    }

    /**
     * Construiește GeoJSON.
     *
     * @param  Collection<int, Tracking>  $points
     * @return array{
     *     type: string,
     *     coordinates: array<int, array{0: float, 1: float}>
     * }
     */
    private function buildGeoJson(Collection $points): array
    {
        return [
            'type' => 'LineString',
            'coordinates' => $points
                ->map(
                    static fn (Tracking $point): array => [
                        (float) $point->longitude,
                        (float) $point->latitude,
                    ]
                )
                ->values()
                ->all(),
        ];
    }
}
