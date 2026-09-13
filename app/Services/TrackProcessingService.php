<?php

namespace App\Services;

use App\Models\Tracking;
use Illuminate\Support\Collection;

class TrackProcessingService
{
    /**
     * @param  Collection<int, Tracking>  $points
     * @return array{distance: float, geojson: array{type: 'LineString', coordinates: list<array{float, float}>}}
     */
    public function process(Collection $points): array
    {
        $ordered = $this->orderPoints($points);

        return [
            'distance' => $this->calculateDistance($ordered),
            'geojson' => $this->buildGeoJson($ordered),
        ];
    }

    /**
     * @param  Collection<int, Tracking>  $points
     * @return Collection<int, Tracking>
     */
    private function orderPoints(Collection $points): Collection
    {
        return $points
            ->values()
            ->sort(function (Tracking $a, Tracking $b): int {
                $aTime = $a->tracked_at?->getTimestamp();
                $bTime = $b->tracked_at?->getTimestamp();

                if ($aTime === $bTime) {
                    return ((int) $a->id) <=> ((int) $b->id);
                }

                if ($aTime === null) {
                    return 1;
                }

                if ($bTime === null) {
                    return -1;
                }

                return $aTime <=> $bTime;
            })
            ->values();
    }

    /** @param Collection<int, Tracking> $points */
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

    private function haversine(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371000.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a =
            sin($dLat / 2) ** 2 +
            cos(deg2rad($lat1)) *
            cos(deg2rad($lat2)) *
            sin($dLon / 2) ** 2;

        $a = min(1.0, max(0.0, $a));

        return 2 * $earthRadius * asin(sqrt($a));
    }

    /**
     * @param  Collection<int, Tracking>  $points
     * @return array{type: 'LineString', coordinates: list<array{float, float}>}
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
