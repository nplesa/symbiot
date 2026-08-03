<?php

namespace App\Jobs;

use App\Models\TrackingSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\TrackProcessingService;

class ProcessTrackingSessionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $sessionId
    ): void {}

    public function handle(TrackProcessingService $trackingService): void
    {
        $session = $this->loadSession();

        if (! $session) {
            return;
        }

        if ($session->trackings->count() < 2) {
            $this->markProcessed($session);

            return;
        }

        $result = $trackingService->process($session->trackings);

        $this->saveResult(
            $session,
            $result['distance'],
            $result['geojson']
        );
    }

    private function loadSession(): ?TrackingSession
    {
        return TrackingSession::with([
            'trackings' => fn ($query) => $query->orderBy('tracked_at'),
        ])->find($this->sessionId);
    }

    private function markProcessed(TrackingSession $session): void
    {
        $session->update([
            'processed_at' => now(),
        ]);
    }

    private function saveResult(
        TrackingSession $session,
        float $distance,
        array $geojson
    ): void {

        $session->update([
            'distance' => round($distance, 2),
            'duration' => $session->started_at->diffInSeconds($session->ended_at),
            'route_geojson' => $geojson,
            'processed_at' => now(),
        ]);
    }

    /**
     * Calculează distanța totală (metri).
     */
    private function calculateDistance($points): float
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
    private function buildGeoJson($points): array
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
