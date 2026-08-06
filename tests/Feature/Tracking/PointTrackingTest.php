<?php

namespace Tests\Feature\Tracking;

use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSession();
    }

    public function test_can_add_tracking_point(): void
    {
        $user = User::factory()->create();

        $trackingSession = TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        session()->put('tracking_session_id', $trackingSession->id);

        $response = $this
            ->actingAs($user)
            ->post(route('app.tracking.point'), [
                'latitude' => 45.657974,
                'longitude' => 25.601198,
                'tracked_at' => now()->toDateTimeString(),
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('trackings', [
            'tracking_session_id' => $trackingSession->id,
            'latitude' => 45.657974,
            'longitude' => 25.601198,
        ]);
    }

    public function test_cannot_add_point_without_active_session(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('app.tracking.point'), [
                'latitude' => 45.657974,
                'longitude' => 25.601198,
                'tracked_at' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'No active tracking session.',
            ]);
    }

    public function test_latitude_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('app.tracking.point'), [
                'longitude' => 25.6,
                'tracked_at' => now()->toDateTimeString(),
            ]);

        $response->assertSessionHasErrors('latitude');
    }

    public function test_longitude_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('app.tracking.point'), [
                'latitude' => 45.6,
                'tracked_at' => now()->toDateTimeString(),
            ]);

        $response->assertSessionHasErrors('longitude');
    }

    public function test_tracked_at_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('app.tracking.point'), [
                'latitude' => 45.6,
                'longitude' => 25.6,
            ]);

        $response->assertSessionHasErrors('tracked_at');
    }

    public function test_guest_cannot_add_tracking_point(): void
    {
        $this->post(route('app.tracking.point'))
            ->assertRedirect('/login');
    }
}
