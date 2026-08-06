<?php

namespace Tests\Feature\Tracking;

use App\Models\Tracking;
use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointsTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_points(): void
    {
        $user = User::factory()->create();

        $session = TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.1,
            'longitude' => 25.1,
            'tracked_at' => now()->subMinute(),
        ]);

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.2,
            'longitude' => 25.2,
            'tracked_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('app.tracking.points', $session));

        $response->assertOk();

        $response->assertJsonCount(2);
    }

    public function test_points_are_ordered(): void
    {
        $user = User::factory()->create();

        $session = TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45,
            'longitude' => 25,
            'tracked_at' => now()->addMinute(),
        ]);

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 46,
            'longitude' => 26,
            'tracked_at' => now(),
        ]);

        $points = $this
            ->actingAs($user)
            ->get(route('app.tracking.points', $session))
            ->json();

        $this->assertLessThan(
            strtotime($points[1]['tracked_at']),
            strtotime($points[0]['tracked_at'])
        );
    }

    public function test_user_cannot_view_other_users_points(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('app.tracking.points', $session))
            ->assertForbidden();
    }

    public function test_guest_cannot_view_points(): void
    {
        $session = TrackingSession::create([
            'user_id' => User::factory()->create()->id,
            'started_at' => now(),
        ]);

        $this->get(route('app.tracking.points', $session))
            ->assertRedirect('/login');
    }
}
