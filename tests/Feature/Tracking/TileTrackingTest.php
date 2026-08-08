<?php

namespace Tests\Feature\Tracking;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TileTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tile_is_proxied(): void
    {
        Http::fake([
            '*' => Http::response('PNGDATA', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/map/tiles/10/100/100');

        $response->assertOk();

        $response->assertHeader('Content-Type', 'image/png');
    }
    public function test_tile_returns_service_unavailable_when_provider_cannot_be_reached(): void
    {
        Http::fake([
            '*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Geoapify unavailable'),
        ]);

        config(['services.geoapify.key' => 'test-key']);

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/map/tiles/12/2340/1461');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '30');
    }

    public function test_tile_does_not_fallback_to_another_provider_when_geoapify_returns_an_error(): void
    {
        Http::fake([
            'https://maps.geoapify.com/*' => Http::response('invalid api key', 403),
            'https://tile.openstreetmap.org/*' => Http::response('OSM', 200, ['Content-Type' => 'image/png']),
        ]);

        config(['services.geoapify.key' => 'test-key']);

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/map/tiles/12/2340/1461');

        $response->assertStatus(503);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://maps.geoapify.com/'));
    }

    public function test_tile_rejects_coordinates_outside_zoom_range(): void
    {
        Http::fake();

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/map/tiles/12/4096/1461');

        $response->assertNotFound();
        Http::assertNothingSent();
    }

}
