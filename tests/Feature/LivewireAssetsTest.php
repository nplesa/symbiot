<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Livewire serves public/vendor/livewire when it has been published (php artisan livewire:publish
 * --assets). That copy must match the installed package: a stale one breaks Livewire after
 * composer update.
 */
class LivewireAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_livewire_assets_match_the_installed_package(): void
    {
        $published = public_path('vendor/livewire/manifest.json');
        if (! file_exists($published)) {
            $this->markTestSkipped('Livewire assets are not published; the package route serves them.');
        }

        $this->assertSame(
            json_decode((string) file_get_contents(base_path('vendor/livewire/livewire/dist/manifest.json')), true),
            json_decode((string) file_get_contents($published), true),
            'public/vendor/livewire is out of date: run php artisan livewire:publish --assets',
        );
    }

    public function test_pages_load_a_livewire_script_that_is_served(): void
    {
        $this->withoutVite();

        $html = $this->actingAs(User::factory()->create())
            ->get(route('app.trasee.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('#<script src="([^"]*/livewire(?:\.min)?\.js)\?id=#', $html);
        preg_match('#<script src="([^"]*/livewire(?:\.min)?\.js)\?id=#', $html, $match);
        $path = (string) parse_url($match[1], PHP_URL_PATH);

        if (str_starts_with($path, '/vendor/livewire/')) {
            $this->assertFileExists(public_path(ltrim($path, '/')));
        } else {
            $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        }
    }
}
