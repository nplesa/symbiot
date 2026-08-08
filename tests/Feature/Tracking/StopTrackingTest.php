<?php

namespace Tests\Feature\Tracking;

use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StopTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSession();
    }

    public function test_can_stop_tracking_session(): void
    {
        $user = User::factory()->create();

        $trackingSession = TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        session()->put('tracking_session_id', $trackingSession->id);

        $response = $this
            ->actingAs($user)
            ->post(route('app.tracking.stop'));

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $trackingSession->refresh();

        $this->assertNotNull($trackingSession->ended_at);
    }

    public function test_stopping_a_session_dispatches_processing_after_commit(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $trackingSession = TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('app.tracking.stop'))
            ->assertOk();

        Queue::assertPushed(\App\Jobs\ProcessTrackingSessionJob::class, function ($job) use ($trackingSession): bool {
            return $job->sessionId === $trackingSession->id;
        });
    }

    public function test_stop_without_active_session_returns_success(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('app.tracking.stop'))
            ->assertOk()
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_guest_cannot_stop_tracking(): void
    {
        $this->post(route('app.tracking.stop'))
            ->assertRedirect('/login');
    }
}
