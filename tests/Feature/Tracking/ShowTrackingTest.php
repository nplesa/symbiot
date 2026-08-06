<?php

namespace Tests\Feature\Tracking;

use App\Models\Tracking;
use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_his_tracking(): void
    {
        $user = User::factory()->create();

        $session = TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => 45.65,
            'longitude' => 25.60,
            'tracked_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('app.tracking.show', $session));

        $response->assertOk();

        $this->assertCount(1, $response->json());
    }

    public function test_user_cannot_view_other_users_tracking(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('app.tracking.show', $session))
            ->assertForbidden();
    }

    public function test_guest_cannot_view_tracking(): void
    {
        $session = TrackingSession::create([
            'user_id' => User::factory()->create()->id,
            'started_at' => now(),
        ]);

        $this->get(route('app.tracking.show', $session))
            ->assertRedirect('/login');
    }
}
