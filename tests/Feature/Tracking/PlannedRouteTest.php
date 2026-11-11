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

    public function test_planned_route_rejects_excessive_coordinate_count(): void
    {
        $user = User::factory()->create();
        $coordinates = array_fill(0, 10001, [25.60, 45.65]);

        $this->actingAs($user)
            ->postJson('/api/v1/tracking/planned', [
                'coordinates' => $coordinates,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('coordinates');
    }

    public function test_planned_route_response_omits_tracking_rows(): void
    {
        $coordinates = [];
        for ($i = 0; $i < 500; $i++) {
            $coordinates[] = [25.60 + $i / 10000, 45.65];
        }

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/tracking/planned', ['coordinates' => $coordinates])
            ->assertCreated()
            ->assertJsonPath('points_count', 500)
            ->assertJsonMissingPath('session.trackings')
            ->assertJsonCount(500, 'session.route_geojson.coordinates');
    }

    public function test_planned_routes_are_capped_per_user(): void
    {
        config(['tracking.planned_routes_max_per_user' => 2]);
        $user = User::factory()->create();
        $payload = ['coordinates' => [[25.60, 45.65], [25.61, 45.66]]];

        $this->actingAs($user)->postJson('/api/v1/tracking/planned', $payload)->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/tracking/planned', $payload)->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/tracking/planned', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('coordinates');

        $this->assertSame(2, TrackingSession::query()->where('user_id', $user->id)->count());
        $this->actingAs(User::factory()->create())->postJson('/api/v1/tracking/planned', $payload)->assertCreated();
    }

    public function test_planned_routes_are_rate_limited(): void
    {
        $user = User::factory()->create();
        $payload = ['coordinates' => [[25.60, 45.65], [25.61, 45.66]]];

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson('/api/v1/tracking/planned', $payload)->assertCreated();
        }

        $this->actingAs($user)->postJson('/api/v1/tracking/planned', $payload)->assertTooManyRequests();
    }

    public function test_web_tracking_routes_use_the_api_rate_limits(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)->postJson('/tracking/stop')->assertSuccessful();
        }
        $this->actingAs($user)->postJson('/tracking/stop')->assertTooManyRequests();

        $routes = collect(app('router')->getRoutes()->getRoutes())->keyBy(fn ($route) => $route->getName());
        $this->assertContains('throttle:tracking-location', $routes['app.tracking.point']->middleware());
        $this->assertContains('throttle:tracking-control', $routes['app.tracking.start']->middleware());
        $this->assertContains('throttle:tracking-control', $routes['app.user.tracking.toggle']->middleware());
    }

    public function test_guest_cannot_create_a_planned_route(): void
    {
        $this->postJson('/api/v1/tracking/planned', [
            'coordinates' => [[25.60, 45.65], [25.61, 45.66]],
        ])->assertUnauthorized();
    }
}
