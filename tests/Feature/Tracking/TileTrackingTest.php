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
}
