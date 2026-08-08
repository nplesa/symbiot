<?php

namespace Tests\Feature\Tracking;

use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingPointValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_tracking_point_is_rejected(): void
    {
        $user = User::factory()->create();
        $session = TrackingSession::factory()->for($user)->create(['status' => 'active', 'ended_at' => null]);

        $response = $this->actingAs($user)->postJson('/api/v1/tracking/location', [
            'session_id' => $session->id,
            'latitude' => 45,
            'longitude' => 25,
            'tracked_at' => now()->subDays(3)->toIso8601String(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('tracked_at');
    }

    public function test_future_tracking_point_is_rejected(): void
    {
        $user = User::factory()->create();
        $session = TrackingSession::factory()->for($user)->create(['status' => 'active', 'ended_at' => null]);

        $response = $this->actingAs($user)->postJson('/api/v1/tracking/location', [
            'session_id' => $session->id,
            'latitude' => 45,
            'longitude' => 25,
            'tracked_at' => now()->addMinutes(10)->toIso8601String(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('tracked_at');
    }

    public function test_retried_identical_point_is_idempotent(): void
    {
        $user = User::factory()->create();
        $session = TrackingSession::factory()->for($user)->create(['status' => 'active', 'ended_at' => null]);
        $payload = [
            'session_id' => $session->id,
            'latitude' => 45.1234567,
            'longitude' => 25.1234567,
            'tracked_at' => now()->subMinute()->toIso8601String(),
        ];

        $first = $this->actingAs($user)->postJson('/api/v1/tracking/location', $payload)->assertSuccessful();
        $second = $this->actingAs($user)->postJson('/api/v1/tracking/location', $payload)->assertSuccessful();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('trackings', 1);
    }
}
