<?php

namespace Tests\Feature\Tracking;

use App\Models\Device;
use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_tracking_lifecycle_uses_one_session(): void
    {
        $user = User::factory()->create();
        $device = Device::create([
            'user_id' => $user->id,
            'uuid' => fake()->uuid(),
            'platform' => 'android',
        ]);

        Sanctum::actingAs($user);

        $start = $this->postJson('/api/v1/tracking/start', [
            'uuid' => $device->uuid,
        ])->assertOk();

        $sessionId = $start->json('data.session_id');

        $this->postJson('/api/v1/tracking/start', [
            'uuid' => $device->uuid,
        ])->assertOk()->assertJsonPath('data.session_id', $sessionId);

        $this->postJson('/api/v1/tracking/location', [
            'session_id' => $sessionId,
            'latitude' => 45.65,
            'longitude' => 25.60,
            'battery' => 73,
            'tracked_at' => now()->toIso8601String(),
        ])->assertOk();

        $this->postJson('/api/v1/tracking/stop', [
            'session_id' => $sessionId,
        ])->assertOk();

        $this->assertDatabaseCount('tracking_sessions', 1);
        $this->assertDatabaseHas('trackings', [
            'tracking_session_id' => $sessionId,
            'battery' => 73,
        ]);
    }

    public function test_stopped_session_rejects_late_location_updates(): void
    {
        $user = User::factory()->create();
        $session = TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tracking/location', [
            'session_id' => $session->id,
            'latitude' => 45.65,
            'longitude' => 25.60,
            'tracked_at' => now()->toIso8601String(),
        ])->assertNotFound();

        $this->assertDatabaseCount('trackings', 0);
    }

    public function test_api_user_cannot_read_another_users_session(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'started_at' => now(),
            'status' => 'active',
        ]);

        Sanctum::actingAs($attacker);

        $this->getJson('/api/v1/tracking/'.$session->id)
            ->assertForbidden();
    }
    public function test_location_endpoint_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $device = Device::create([
            'user_id' => $user->id,
            'uuid' => fake()->uuid(),
            'platform' => 'android',
        ]);

        Sanctum::actingAs($user);

        $sessionId = $this->postJson('/api/v1/tracking/start', [
            'uuid' => $device->uuid,
        ])->json('data.session_id');

        for ($i = 0; $i < 180; $i++) {
            $this->postJson('/api/v1/tracking/location', [
                'session_id' => $sessionId,
                'latitude' => 45.65,
                'longitude' => 25.60,
                'tracked_at' => now()->toIso8601String(),
            ])->assertOk();
        }

        $this->postJson('/api/v1/tracking/location', [
            'session_id' => $sessionId,
            'latitude' => 45.65,
            'longitude' => 25.60,
            'tracked_at' => now()->toIso8601String(),
        ])->assertStatus(429);
    }

}
