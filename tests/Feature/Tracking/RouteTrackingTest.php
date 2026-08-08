<?php

namespace Tests\Feature\Tracking;

use App\Models\Tracking;
use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_line_string_for_tracking_points(): void
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
            'tracked_at' => now(),
        ]);

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.1,
            'longitude' => 25.1,
            'tracked_at' => now()->addMinute(),
        ]);

        $this->actingAs($user)
            ->get(route('app.tracking.route', $session))
            ->assertOk()
            ->assertJson([
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [
                        [25, 45],
                        [25.1, 45.1],
                    ],
                ],
            ]);
    }

    public function test_user_cannot_view_other_users_route(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('app.tracking.route', $session))
            ->assertForbidden();
    }

}
