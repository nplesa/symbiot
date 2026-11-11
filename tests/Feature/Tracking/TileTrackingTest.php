<?php

namespace Tests\Feature\Tracking;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TileTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep tests away from tiles cached by the real app in storage/framework/cache/tiles.
        config(['cache.stores.tiles.path' => storage_path('framework/testing/tile-cache')]);
        Cache::store('tiles')->flush();
    }

    protected function tearDown(): void
    {
        Cache::store('tiles')->flush();

        parent::tearDown();
    }

    public function test_tile_is_proxied(): void
    {
        Cache::store('tiles')->forget('geoapify-map-tile-v1:10:100:100');
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

    public function test_map_tile_is_cached_on_disk_and_reuses_cached_response(): void
    {
        $cacheKey = 'geoapify-map-tile-v1:10:100:100';
        Cache::store('tiles')->forget($cacheKey);
        Http::fake([
            '*' => Http::response('PNGDATA', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/map/tiles/10/100/100')->assertOk();
        $this->actingAs($user)
            ->get('/map/tiles/10/100/100')
            ->assertOk()
            ->assertContent('PNGDATA')
            ->assertHeader('Cache-Control', 'max-age=604800, public, s-maxage=604800');

        Http::assertSentCount(1);
        Cache::store('tiles')->forget($cacheKey);
    }

    public function test_tile_returns_service_unavailable_when_provider_cannot_be_reached(): void
    {
        Http::fake([
            '*' => fn () => throw new ConnectionException('Geoapify unavailable'),
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

    public function test_tile_rejects_zoom_above_provider_maximum_and_non_numeric_coordinates(): void
    {
        Http::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->get('/map/tiles/21/0/0')->assertNotFound();
        $this->actingAs($user)->get('/map/tiles/12/abc/1461')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_tile_requests_are_rate_limited_per_user(): void
    {
        Http::fake(['*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
        config(['services.geoapify.key' => 'test-key']);
        Cache::store('tiles')->put('geoapify-map-tile-v1:10:100:100', 'PNGDATA', now()->addMinute());

        $user = User::factory()->create();

        for ($i = 0; $i < 300; $i++) {
            $this->actingAs($user)->get('/map/tiles/10/100/100')->assertOk();
        }

        $this->actingAs($user)->get('/map/tiles/10/100/100')->assertTooManyRequests();
        $this->actingAs(User::factory()->create())->get('/map/tiles/10/100/100')->assertOk();
    }

    public function test_prune_command_deletes_only_tiles_older_than_the_cutoff(): void
    {
        $directory = config('cache.stores.tiles.path') . '/ab/cd';
        File::ensureDirectoryExists($directory);
        $old = $directory . '/old-tile';
        $fresh = $directory . '/fresh-tile';
        file_put_contents($old, 'OLD');
        file_put_contents($fresh, 'FRESH');
        touch($old, now()->subDays(31)->getTimestamp());

        $this->artisan('map:prune-tiles', ['--days' => 30])
            ->expectsOutputToContain('Deleted 1 cached tiles')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
    }
}
