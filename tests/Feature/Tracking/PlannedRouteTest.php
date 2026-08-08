<?php

namespace Tests\Feature\Tracking;

use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlannedRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_planned_route(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/tracking/planned', [
            'name' => 'Centru - Parc',
            'coordinates' => [
                [25.60, 45.65],
                [25.61, 45.66],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('session.type', 'planned')
            ->assertJsonPath('session.name', 'Centru - Parc')
            ->assertJsonPath('session.route_geojson.type', 'LineString');

        $session = TrackingSession::query()->firstOrFail();

        $this->assertSame($user->id, $session->user_id);
        $this->assertSame(2, $session->trackings()->count());
        $this->assertDatabaseHas('trackings', [
            'tracking_session_id' => $session->id,
            'type' => 'planned',
            'source' => 'manual',
            'sequence' => 1,
            'latitude' => 45.66,
            'longitude' => 25.61,
        ]);
    }

    public function test_planned_route_requires_at_least_two_coordinates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/tracking/planned', [
                'coordinates' => [[25.60, 45.65]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('coordinates');
    }

    public function test_guest_cannot_create_a_planned_route(): void
    {
        $this->postJson('/api/v1/tracking/planned', [
            'coordinates' => [[25.60, 45.65], [25.61, 45.66]],
        ])->assertUnauthorized();
    }
}
