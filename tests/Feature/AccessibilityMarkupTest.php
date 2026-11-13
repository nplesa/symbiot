<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AccessibilityMarkupTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_font_awesome_icon_in_the_views_is_hidden_from_screen_readers(): void
    {
        $missing = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/<i\s[^>]*class="[^"]*\b(?:fa|fa-solid|fa-regular|fa-brands)\b[^"]*"[^>]*>/', $file->getContents(), $icons);
            foreach ($icons[0] as $icon) {
                if (! str_contains($icon, 'aria-hidden="true"')) {
                    $missing[] = $file->getRelativePathname() . ': ' . $icon;
                }
            }
        }

        $this->assertSame([], $missing, 'Decorative icons need aria-hidden="true"; label the button or link instead.');
    }

    public function test_language_links_are_labelled_and_mark_the_current_language(): void
    {
        $this->withoutVite();

        $html = $this->actingAs(User::factory()->create())
            ->withSession(['locale' => 'ro'])
            ->get(route('app.home'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('#title="English" aria-label="English" hreflang="en" lang="en"\s*>#', $html);
        $this->assertMatchesRegularExpression('#aria-label="Română" hreflang="ro" lang="ro"\s+aria-current="true"#', $html);
        $this->assertMatchesRegularExpression('#title="Italiano" aria-label="Italiano" hreflang="it" lang="it"\s*>#', $html);
    }

    public function test_the_romanian_flag_reads_blue_yellow_red(): void
    {
        $layout = File::get(resource_path('views/layouts/app.blade.php'));

        // Later rects are drawn on top and cover the left part: full width = right stripe, width 1 = left stripe.
        $this->assertMatchesRegularExpression(
            '#<rect width="3" height="2" fill="\#CE1126"/>\s*<rect width="2" height="2" fill="\#FCD116"/>\s*<rect width="1" height="2" fill="\#002B7F"/>#',
            $layout
        );
    }

    public function test_dashboard_status_indicators_are_announced(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->create())
            ->get(route('app.home'))
            ->assertOk()
            ->assertSee('class="btn locate-user dashboard-button" role="img" aria-label="Location"', false)
            ->assertSee('class="btn track-user dashboard-button" role="img" aria-label="Tracking Me"', false);
    }

    public function test_the_location_button_label_describes_the_action_and_keeps_the_visible_word(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->create())
            ->withSession(['locale' => 'ro'])
            ->get(route('app.home'))
            ->assertOk()
            ->assertSee('id="dashboard-use-location" aria-label="Folosește locația mea"', false);
    }
}
