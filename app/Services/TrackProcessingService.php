<?php

namespace App\Services;

use Illuminate\Support\Collection;

class TrackProcessingService
{
    /**
     * Procesează punctele GPS.
     */
    public function process(Collection $points): array
    {
        return [
            'distance' => $this->calculateDistance($points),
            'geojson'  => $this->buildGeoJson($points),
        ];
    }

    /**
     * Calculează distanța totală.
     */
    private function calculateDistance(Collection $points): float
    {
        $distance = 0;

        for ($i = 1; $i < $points->count(); $i++) {

            $distance += $this->haversine(
                $points[$i - 1]->latitude,
                $points[$i - 1]->longitude,
                $points[$i]->latitude,
                $points[$i]->longitude
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
     */
    private function buildGeoJson(Collection $points): array
    {
        return [
            'type' => 'LineString',
            'coordinates' => $points
                ->map(fn ($point) => [
                    (float) $point->longitude,
                    (float) $point->latitude,
                ])
                ->values()
                ->all(),
        ];
    }
}