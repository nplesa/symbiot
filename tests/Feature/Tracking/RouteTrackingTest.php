<?php

namespace Tests\Feature\Tracking;

use App\Models\Tracking;
use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RouteTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_feature_collection_when_less_than_two_points(): void
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

        $this->actingAs($user)
            ->get(route('app.tracking.route', $session))
            ->assertOk()
            ->assertJson([
                'type' => 'FeatureCollection',
                'features' => [],
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

    public function test_geoapify_response_is_returned(): void
    {
        Http::fake([
            '*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [['id' => 1]],
            ]),
        ]);

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
                'type' => 'FeatureCollection',
            ]);
    }
}
