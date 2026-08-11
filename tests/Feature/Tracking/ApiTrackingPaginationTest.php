<?php

namespace Tests\Feature\Tracking;

use App\Models\Tracking;
use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTrackingPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sessions_are_paginated(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 30; $i++) {
            TrackingSession::create([
                'user_id' => $user->id,
                'started_at' => now()->subMinutes($i),
                'status' => 'completed',
            ]);
        }

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/tracking/sessions?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.per_page', 10)
            ->assertJsonCount(10, 'data.data');

        $this->assertSame(30, $response->json('data.total'));
    }

    public function test_points_are_paginated(): void
    {
        $user = User::factory()->create();
        $session = TrackingSession::create([
            'user_id' => $user->id,
            'started_at' => now()->subHour(),
            'status' => 'completed',
        ]);

        for ($i = 0; $i < 25; $i++) {
            Tracking::create([
                'tracking_session_id' => $session->id,
                'latitude' => 45 + ($i / 1000),
                'longitude' => 25 + ($i / 1000),
                'tracked_at' => now()->subSeconds(25 - $i),
            ]);
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/tracking/' . $session->id . '/points?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.per_page', 10)
            ->assertJsonCount(10, 'data.data');
    }

    public function test_pagination_limits_are_enforced(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/tracking/sessions?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }
}
