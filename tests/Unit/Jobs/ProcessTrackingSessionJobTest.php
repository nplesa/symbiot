<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessTrackingSessionJob;
use App\Models\Device;
use App\Models\Tracking;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\TrackProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessTrackingSessionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_does_nothing_when_session_does_not_exist(): void
    {
        $job = new ProcessTrackingSessionJob(999999);

        $job->handle(app(TrackProcessingService::class));

        $this->assertDatabaseCount('tracking_sessions', 0);
    }

    public function test_session_with_less_than_two_points_is_marked_as_processed(): void
    {
        $session = $this->createSession();

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.6486,
            'longitude' => 25.6061,
            'tracked_at' => now(),
        ]);

        $job = new ProcessTrackingSessionJob($session->id);

        $job->handle(app(TrackProcessingService::class));

        $session->refresh();

        $this->assertNotNull($session->processed_at);
        $this->assertSame(0.0, $session->distance);
        $this->assertNull($session->route_geojson);
    }

    public function test_an_unnamed_session_gets_a_route_named_after_its_local_start_time(): void
    {
        $session = $this->createSession();
        // 06:15 UTC on 1 July is 09:15 in Bucharest (summer time).
        $session->forceFill(['name' => null, 'started_at' => '2026-07-01 06:15:00'])->save();
        foreach ([[45.6486, 25.6061, 10], [45.6496, 25.6071, 0]] as [$lat, $lon, $secondsAgo]) {
            Tracking::create([
                'tracking_session_id' => $session->id,
                'latitude' => $lat,
                'longitude' => $lon,
                'tracked_at' => now()->subSeconds($secondsAgo),
            ]);
        }

        (new ProcessTrackingSessionJob($session->id))->handle(app(TrackProcessingService::class));

        $this->assertDatabaseHas('routes', [
            'source_url' => 'tracking-session:' . $session->id,
            'name' => 'Tracking 01.07.2026 09:15',
        ]);
    }

    public function test_session_with_two_or_more_points_is_processed(): void
    {
        $session = $this->createSession();

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.6486,
            'longitude' => 25.6061,
            'tracked_at' => now()->subSeconds(10),
        ]);

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.6496,
            'longitude' => 25.6071,
            'tracked_at' => now(),
        ]);

        $job = new ProcessTrackingSessionJob($session->id);

        $job->handle(app(TrackProcessingService::class));

        $session->refresh();

        $this->assertNotNull($session->processed_at);
        $this->assertNotNull($session->distance);
        $this->assertGreaterThan(0, $session->distance);
        $this->assertIsArray($session->route_geojson);
        $this->assertDatabaseHas('routes', [
            'user_id' => $session->user_id,
            'source' => 'tracking',
            'source_url' => 'tracking-session:' . $session->id,
        ]);
        $this->assertDatabaseCount('route_points', 2);

        (new ProcessTrackingSessionJob($session->id))->handle(app(TrackProcessingService::class));
        $this->assertDatabaseCount('routes', 1);
    }

    public function test_processed_session_contains_correct_geojson(): void
    {
        $session = $this->createSession();

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.6486,
            'longitude' => 25.6061,
            'tracked_at' => now()->subSeconds(10),
        ]);

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.6496,
            'longitude' => 25.6071,
            'tracked_at' => now(),
        ]);

        $job = new ProcessTrackingSessionJob($session->id);

        $job->handle(app(TrackProcessingService::class));

        $session->refresh();

        $this->assertSame('LineString', $session->route_geojson['type']);

        $this->assertSame(
            [
                [25.6061, 45.6486],
                [25.6071, 45.6496],
            ],
            $session->route_geojson['coordinates']
        );
    }

    public function test_processed_session_calculates_duration_from_start_and_end(): void
    {
        $session = $this->createSession();

        $session->update([
            'started_at' => now()->subMinutes(5),
            'ended_at' => now(),
        ]);

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.6486,
            'longitude' => 25.6061,
            'tracked_at' => now()->subSeconds(10),
        ]);

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.6496,
            'longitude' => 25.6071,
            'tracked_at' => now(),
        ]);

        $job = new ProcessTrackingSessionJob($session->id);

        $job->handle(app(TrackProcessingService::class));

        $session->refresh();

        $this->assertGreaterThanOrEqual(299, $session->duration);
        $this->assertLessThanOrEqual(301, $session->duration);
    }

    public function test_active_session_is_not_processed_by_the_job(): void
    {
        $session = $this->createSession();
        $session->update([
            'status' => 'active',
            'ended_at' => null,
        ]);

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.6486,
            'longitude' => 25.6061,
            'tracked_at' => now(),
        ]);

        (new ProcessTrackingSessionJob($session->id))
            ->handle(app(TrackProcessingService::class));

        $session->refresh();

        $this->assertNull($session->processed_at);
    }

    public function test_long_sessions_are_streamed_in_tracking_order_across_pages(): void
    {
        $session = $this->createSession();
        $start = now()->subHours(2)->startOfSecond();
        $rows = [];
        // 2500 points over several 1000-row pages; pairs share a timestamp, so the
        // (tracked_at, id) keyset must not skip or repeat points at page boundaries.
        // Inserted in reverse time order to prove the job orders by tracked_at.
        for ($i = 2499; $i >= 0; $i--) {
            $rows[] = [
                'tracking_session_id' => $session->id,
                'latitude' => 45.0 + $i / 100000,
                'longitude' => 25.0,
                'tracked_at' => $start->copy()->addSeconds(intdiv($i, 2)),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Tracking::query()->insert($chunk);
        }

        (new ProcessTrackingSessionJob($session->id))->handle(app(TrackProcessingService::class));

        $coordinates = $session->fresh()->route_geojson['coordinates'];
        $this->assertCount(2500, $coordinates);

        // Order is (tracked_at, id). Within a shared timestamp the point inserted first
        // (the higher index, because of the reverse insert) has the lower id: 1,0,3,2,5,4...
        $expectedOrder = [];
        for ($pair = 0; $pair < 1250; $pair++) {
            $expectedOrder[] = $pair * 2 + 1;
            $expectedOrder[] = $pair * 2;
        }
        $this->assertSame(
            array_map(fn (int $i): float => round(45.0 + $i / 100000, 7), $expectedOrder),
            array_map(fn ($latitude): float => round($latitude, 7), array_column($coordinates, 1))
        );

        // 1250 in-pair steps of ~1.11 m plus 1249 steps of 3 points between pairs.
        $this->assertEqualsWithDelta((1250 + 1249 * 3) * 1.1119, $session->fresh()->distance, 5.0);
    }

    public function test_job_has_retry_timeout_and_unique_lock_expiry(): void
    {
        $job = new ProcessTrackingSessionJob(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame(600, $job->timeout);
        $this->assertSame([60, 300], $job->backoff);
        $this->assertSame(3600, $job->uniqueFor);
    }

    private function createSession(): TrackingSession
    {
        $user = User::factory()->create();

        $device = Device::create([
            'user_id' => $user->id,
            'uuid' => (string) Str::uuid(),
            'platform' => 'android',
        ]);

        return TrackingSession::create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
        ]);
    }
}
