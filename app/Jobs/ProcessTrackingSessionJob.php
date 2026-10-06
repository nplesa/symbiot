<?php

namespace App\Jobs;

use App\Models\Route;
use App\Models\RoutePoint;
use App\Models\TrackingSession;
use App\Services\TrackProcessingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessTrackingSessionJob implements ShouldBeUnique, ShouldQueue
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

    /** @param array<string, mixed> $geojson */
    private function saveResult(
        TrackingSession $session,
        float $distance,
        array $geojson
    ): void {

        DB::transaction(function () use ($session, $distance, $geojson): void {
            $duration = $session->started_at->diffInSeconds($session->ended_at);

            $session->update([
                'distance' => round($distance, 2),
                'duration' => $duration,
                'route_geojson' => $geojson,
                'processed_at' => now(),
            ]);

            $this->saveRoute($session, $distance, $duration, $geojson);
        });
    }

    /** @param array<string, mixed> $geojson */
    private function saveRoute(
        TrackingSession $session,
        float $distance,
        int $duration,
        array $geojson
    ): void {
        $sourceUrl = 'tracking-session:' . $session->id;

        if (Route::query()->where('user_id', $session->user_id)->where('source_url', $sourceUrl)->exists()) {
            return;
        }

        $route = Route::create([
            'user_id' => $session->user_id,
            'name' => $session->name ?: 'Tracking ' . $session->started_at->format('d.m.Y H:i'),
            'format' => 'geojson',
            'geometry' => $geojson,
            'distance' => round($distance, 2),
            'duration' => $duration,
            'source' => 'tracking',
            'source_url' => $sourceUrl,
        ]);

        $now = now();

        foreach (array_chunk($geojson['coordinates'], 500, true) as $chunk) {
            RoutePoint::query()->insert(array_map(
                static fn (array $coordinate, int $index): array => [
                    'route_id' => $route->id,
                    'sequence' => $index,
                    'longitude' => $coordinate[0],
                    'latitude' => $coordinate[1],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $chunk,
                array_keys($chunk)
            ));
        }
    }
}
