<?php

namespace App\Jobs;

use App\Models\TrackingSession;
use App\Services\TrackProcessingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTrackingSessionJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function __construct(
        public int $sessionId
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->sessionId;
    }

    public function handle(TrackProcessingService $trackingService): void
    {
        $session = $this->loadSession();

        if (! $session || $session->processed_at !== null || $session->status !== 'completed') {
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
            'trackings' => fn ($query) => $query
                ->orderBy('tracked_at')
                ->orderBy('id'),
        ])->find($this->sessionId);
    }

    private function markProcessed(TrackingSession $session): void
    {
        $session->update([
            'processed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $geojson
     */
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
}
