<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Livewire's JS must come from the installed package (its own route), not from a
 * copy published to public/vendor/livewire that goes stale on composer update.
 */
class LivewireAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_load_livewire_from_the_package_route(): void
    {
        $this->assertFileDoesNotExist(public_path('vendor/livewire/manifest.json'));
        $this->withoutVite();

        $html = $this->actingAs(User::factory()->create())
            ->get(route('app.trasee.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('#<script src="[^"]*/livewire-[^"/]+/livewire(\.min)?\.js\?id=#', $html);
        $this->assertStringNotContainsString('/vendor/livewire/', $html);

        preg_match('#<script src="([^"]*/livewire-[^"/]+/livewire(?:\.min)?\.js)\?id=#', $html, $match);
        $this->get(parse_url($match[1], PHP_URL_PATH))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    }
}
