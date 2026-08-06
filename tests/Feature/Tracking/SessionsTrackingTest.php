<?php

namespace Tests\Feature\Tracking;

use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionsTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_receives_only_his_sessions_for_selected_date(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => '2026-08-06 08:00:00',
        ]);

        TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => '2026-08-06 12:00:00',
        ]);

        TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => '2026-08-07 09:00:00',
        ]);

        TrackingSession::create([
            'user_id' => $otherUser->id,
            'started_at' => '2026-08-06 10:00:00',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('app.tracking.sessions', [
                'date' => '2026-08-06',
            ]));

        $response->assertOk();

        $response->assertJsonCount(2);

        $response->assertJsonFragment([
            'started_at' => '2026-08-06T08:00:00.000000Z',
        ]);

        $response->assertJsonFragment([
            'started_at' => '2026-08-06T12:00:00.000000Z',
        ]);
    }

    public function test_sessions_are_ordered_by_started_at(): void
    {
        $user = User::factory()->create();

        TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => '2026-08-06 15:00:00',
        ]);

        TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => '2026-08-06 08:00:00',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('app.tracking.sessions', [
                'date' => '2026-08-06',
            ]));

        $response->assertOk();

        $sessions = $response->json();

        $this->assertEquals(
            '2026-08-06T08:00:00.000000Z',
            $sessions[0]['started_at']
        );

        $this->assertEquals(
            '2026-08-06T15:00:00.000000Z',
            $sessions[1]['started_at']
        );
    }

    public function test_guest_cannot_view_sessions(): void
    {
        $this->get(route('app.tracking.sessions', [
            'date' => now()->toDateString(),
        ]))->assertRedirect('/login');
    }
}
