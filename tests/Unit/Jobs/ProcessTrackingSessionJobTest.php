<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessTrackingSessionJob;
use App\Models\Tracking;
use App\Models\TrackingSession;
use App\Models\User;
use App\Models\Device;
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
