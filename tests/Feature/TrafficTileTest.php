<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrafficTileTest extends TestCase
{
    use RefreshDatabase;

    public function test_tiles_require_authentication(): void
    {
        Http::fake();
        $this->get('/map/traffic/2/1/1')->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    public function test_tile_is_proxied_and_cached_without_exposing_key(): void
    {
        config(['services.tomtom.key' => 'secret-test-key']);
        $png = "\x89PNG\r\n\x1a\n" . 'test';
        Http::fake(['api.tomtom.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 2; $i++) {
            $this->get('/map/traffic/2/1/1')->assertOk()
                ->assertHeader('Content-Type', 'image/png')->assertContent($png);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('TomTom-Api-Key', 'secret-test-key')
            && str_contains($request->url(), '/maps/orbis/traffic/flow/raster/tile/2/1/1')
            && ! str_contains($request->url(), 'secret-test-key'));
        $this->travel(61)->seconds();
        $this->get('/map/traffic/2/1/1')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_invalid_coordinates_and_missing_key_do_not_call_provider(): void
    {
        Http::fake();
        config(['services.tomtom.key' => null]);
        $this->actingAs(User::factory()->create());
        foreach (['23/0/0', '2/4/1', '2/1/4', '2/-1/0', 'text/0/0'] as $tile) {
            $this->get('/map/traffic/' . $tile)->assertNotFound();
        }
        $this->get('/map/traffic/2/1/1')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        Http::assertNothingSent();
    }

    public function test_provider_errors_are_not_exposed_or_cached(): void
    {
        config(['services.tomtom.key' => 'secret-test-key']);
        Http::fake(['api.tomtom.com/*' => Http::sequence()
            ->push('secret-test-key', 429)
            ->push('not a png', 200)
            ->pushResponse(Http::failedConnection())]);
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 3; $i++) {
            $this->get('/map/traffic/2/1/1')->assertStatus(503)->assertDontSee('secret-test-key');
        }
        Http::assertSentCount(3);
    }
}
