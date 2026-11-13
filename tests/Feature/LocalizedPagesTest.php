<?php

namespace Tests\Feature;

use App\Models\Route;
use App\Models\User;
use App\Services\FuelPriceService;
use App\Services\PoiCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizedPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_poi_categories_and_fuels_follow_the_current_locale(): void
    {
        app()->setLocale('ro');
        $categories = app(PoiCatalog::class)->categories();
        $this->assertSame('Benzinării', $categories['fuel']['label']);
        $this->assertSame('Servicii și opriri', $categories['fuel']['group_label']);
        $this->assertSame('police', $categories['police']['group']);
        $this->assertSame('Benzină standard', FuelPriceService::categories()['11']);

        app()->setLocale('it');
        $this->assertSame('Distributori di carburante', app(PoiCatalog::class)->categories()['fuel']['label']);
        $this->assertSame('Gasolio standard', FuelPriceService::categories()['21']);
    }

    public function test_layout_hands_the_locale_translations_to_the_front_end(): void
    {
        $this->withoutVite();

        $html = $this->actingAs(User::factory()->create())->withSession(['locale' => 'ro'])
            ->get(route('app.profile.edit'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('window.SymbiotI18n', $html);
        $this->assertStringContainsString('"locale":"ro"', $html);
        // json_encode escapes non-ASCII characters ("ă" becomes ă in the page source).
        $this->assertStringContainsString(
            '"Fuel stations":' . json_encode('Benzinării', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            $html
        );
        $this->assertLessThan(
            strpos($html, '</head>'),
            strpos($html, 'window.SymbiotI18n'),
            'Translations must be defined before the module scripts run.'
        );
    }

    public function test_pages_render_in_the_session_locale(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $route = Route::create([
            'user_id' => $user->id,
            'name' => 'Traseu',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[25.6, 45.65], [25.61, 45.66]]],
        ]);

        $this->actingAs($user)->withSession(['locale' => 'ro'])
            ->get(route('app.navigation.show', $route))
            ->assertOk()
            ->assertSee('Oprește navigația')
            ->assertSee('<legend>Poliție</legend>', false);

        $this->actingAs($user)->withSession(['locale' => 'it'])
            ->get(route('app.profile.edit'))
            ->assertOk()
            ->assertSee('Il mio profilo')
            ->assertSee('Salva modifiche');
    }
}
