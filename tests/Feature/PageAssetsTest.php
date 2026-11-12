<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

class PageAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Render a marker per entry point instead of real tags, so tests don't need a build.
        $this->app->instance(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString(implode('', array_map(
                    fn (string $entry): string => "<!--vite:{$entry}-->",
                    (array) $entrypoints
                )));
            }
        });
    }

    private function assertInHead(string $html, string $entry): void
    {
        $marker = strpos($html, "<!--vite:{$entry}-->");
        $this->assertNotFalse($marker, "{$entry} is not loaded.");
        $this->assertLessThan(strpos($html, '</head>'), $marker, "{$entry} is loaded after </head> (flash of unstyled content).");
    }

    public function test_page_styles_are_loaded_in_the_head(): void
    {
        $user = User::factory()->create();

        $home = $this->actingAs($user)->get(route('app.home'))->assertOk()->getContent();
        $this->assertInHead($home, 'resources/sass/pages/dashboard.scss');

        $profile = $this->actingAs($user)->get(route('app.profile.edit'))->assertOk()->getContent();
        $this->assertInHead($profile, 'resources/sass/pages/profile.scss');
    }

    public function test_theme_is_applied_before_the_page_content(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('app.home'))->getContent();

        $shell = strpos($html, 'id="symbiotShell"');
        $themeScript = strpos($html, "localStorage.getItem('symbiot-theme')");

        $this->assertNotFalse($themeScript);
        $this->assertGreaterThan($shell, $themeScript);
        $this->assertLessThan(strpos($html, 'symbiot-sidebar'), $themeScript, 'The theme must be set before the sidebar is painted.');
    }
}
