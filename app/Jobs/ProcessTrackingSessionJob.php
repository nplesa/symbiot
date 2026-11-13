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
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTrackingSessionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** A day of tracking can hold ~260k points. */
    public int $timeout = 600;

    /** Releases the uniqueness lock even if a worker dies mid-job, so the session can be retried. */
    public int $uniqueFor = 3600;

    public function __construct(
        public int $sessionId
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->sessionId;
    }

    public function handle(TrackProcessingService $trackingService): void
    {
        $session = TrackingSession::find($this->sessionId);

        if (! $session || $session->processed_at !== null || $session->status !== 'completed') {
            return;
        }

        if ($session->trackings()->count() < 2) {
            $this->markProcessed($session);

            return;
        }

        $result = $trackingService->processOrdered($this->orderedPoints($session->id));

        $this->saveResult(
            $session,
            $result['distance'],
            $result['geojson']
        );
    }

    /**
     * Plain rows in tracking order, 1000 at a time, instead of eager-loaded models.
     * Keyset paging on (tracked_at, id) uses the (tracking_session_id, tracked_at)
     * index and avoids OFFSET scans on sessions with hundreds of thousands of points.
     *
     * @return \Generator<int, object{id: int, tracked_at: string, latitude: string, longitude: string}>
     */
    private function orderedPoints(int $sessionId): \Generator
    {
        $last = null;

        do {
            $page = DB::table('trackings')
                ->select(['id', 'tracked_at', 'latitude', 'longitude'])
                ->where('tracking_session_id', $sessionId)
                ->when($last !== null, fn ($query) => $query->where(fn ($after) => $after
                    ->where('tracked_at', '>', $last->tracked_at)
                    ->orWhere(fn ($sameTime) => $sameTime
                        ->where('tracked_at', $last->tracked_at)
                        ->where('id', '>', $last->id))))
                ->orderBy('tracked_at')
                ->orderBy('id')
                ->limit(1000)
                ->get();

            foreach ($page as $point) {
                yield $point;
                $last = $point;
            }
        } while ($page->count() === 1000);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Tracking session processing failed.', [
            'session_id' => $this->sessionId,
            'message' => $exception?->getMessage(),
        ]);
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
            // Carbon 3 returns fractional seconds; the duration column and saveRoute() take whole seconds.
            $duration = (int) round($session->started_at->diffInSeconds($session->ended_at));

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
            'name' => $session->name ?: 'Tracking ' . $session->started_at->copy()->setTimezone(config('app.display_timezone'))->format('d.m.Y H:i'),
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
