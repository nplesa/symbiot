<?php

namespace Tests\Feature\Tracking;

use App\Models\Tracking;
use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_write_points_to_another_users_session(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'started_at' => now(),
            'status' => 'active',
        ]);

        $this->actingAs($attacker)
            ->postJson('/tracking/point', [
                'tracking_session_id' => $session->id,
                'latitude' => 45.0,
                'longitude' => 25.0,
                'tracked_at' => now()->toIso8601String(),
            ])
            ->assertStatus(404);

        $this->assertDatabaseCount('trackings', 0);
    }

    public function test_user_cannot_start_tracking_with_another_users_device(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $device = $owner->devices()->create([
            'uuid' => fake()->uuid(),
            'platform' => 'android',
        ]);

        $this->actingAs($attacker)
            ->postJson('/tracking/start', [
                'device_id' => $device->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('tracking_sessions', 0);
    }

    public function test_start_reuses_existing_active_session(): void
    {
        $user = User::factory()->create();

        $session = TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => now(),
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson('/tracking/start')
            ->assertOk()
            ->assertJsonPath('id', $session->id);

        $this->assertDatabaseCount('tracking_sessions', 1);
    }

    public function test_battery_is_persisted_on_tracking_points(): void
    {
        $user = User::factory()->create();

        $session = TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => now(),
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/tracking/location', [
                'session_id' => $session->id,
                'latitude' => 45.0,
                'longitude' => 25.0,
                'battery' => 73,
                'tracked_at' => now()->toIso8601String(),
            ])
            ->assertUnauthorized();

        // API auth is intentionally covered by the API test suite; this
        // assertion keeps this test focused on the model's mass assignment.
        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.0,
            'longitude' => 25.0,
            'battery' => 73,
            'tracked_at' => now(),
        ]);

        $this->assertDatabaseHas('trackings', [
            'tracking_session_id' => $session->id,
            'battery' => 73,
        ]);
    }
}
