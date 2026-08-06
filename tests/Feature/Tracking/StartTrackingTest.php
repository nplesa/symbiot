<?php

namespace Tests\Feature\Tracking;

use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StartTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_start_tracking(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('app.tracking.start'));

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseCount('tracking_sessions', 1);

        $this->assertDatabaseHas('tracking_sessions', [
            'user_id' => $user->id,
        ]);

        $trackingSession = TrackingSession::first();

        $this->assertNotNull($trackingSession);
        $this->assertNotNull($trackingSession->started_at);
        $this->assertNull($trackingSession->ended_at);
    }

    public function test_second_start_does_not_create_new_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        // Prima pornire
        $this->post(route('app.tracking.start'));

        // Preluăm ID-ul sesiunii create și îl punem în sesiunea HTTP,
        // simulând același browser.
        $trackingSession = TrackingSession::first();

        $this->startSession();
        session()->put('tracking_session_id', $trackingSession->id);

        // A doua pornire
        $response = $this->post(route('app.tracking.start'));

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseCount('tracking_sessions', 1);
    }

    public function test_guest_cannot_start_tracking(): void
    {
        $this->post(route('app.tracking.start'))
            ->assertRedirect('/login');
    }
}
