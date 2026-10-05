<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PoiCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FuelBrandSubcategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_fuel_lists_romanian_brands_as_subcategories(): void
    {
        $labels = collect(app(PoiCatalog::class)->subcategories('fuel'))->pluck('label')->all();

        foreach (['Petrom', 'OMV', 'Rompetrol', 'MOL', 'Lukoil', 'Socar', 'Gazprom'] as $brand) {
            $this->assertContains($brand, $labels);
        }
    }

    public function test_selecting_a_brand_returns_only_its_stations_as_fuel_points(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => [
            ['type' => 'node', 'id' => 1, 'lat' => 44.43, 'lon' => 26.10, 'tags' => ['amenity' => 'fuel', 'brand' => 'Petrom', 'name' => 'Petrom']],
        ]])]);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/poi/fuel?lat=44.43&lon=26.10&radius=2000&types=subcategory:fuel:petrom');

        $response->assertOk()->assertJsonPath('0.type', 'fuel');
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), '"brand"~"^(Petrom|PETROM|OMV Petrom)$"')
            && ! str_contains(urldecode($r->url()), 'Lukoil'));
    }
}
