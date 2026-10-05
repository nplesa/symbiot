<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PoiSubcategoryAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.geoapify.key' => null]);
    }

    private function station(array $tags, float $lat, float $lon): void
    {
        DB::table('transport_points')->insert([
            'name' => $tags['name'] ?? 'Benzinărie', 'type' => 'fuel', 'source' => 'openstreetmap',
            'osm_type' => 'n', 'osm_id' => (string) random_int(1, 999999), 'tags' => json_encode($tags + ['amenity' => 'fuel']),
            'lat' => $lat, 'lon' => $lon, 'imported_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_lists_only_brands_present_around_the_location(): void
    {
        Cache::flush();
        $this->station(['brand' => 'Petrom'], 44.43, 26.10);
        $this->station(['operator' => 'OMV', 'fuel:lpg' => 'yes'], 44.431, 26.101);
        $this->station(['brand' => 'Lukoil'], 45.80, 24.15);

        $brands = $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/subcategories?lat=44.43&lon=26.10&radius=3000')
            ->assertOk()
            ->json('subcategories.fuel');

        $this->assertEqualsCanonicalizing(['subcategory:fuel:petrom', 'subcategory:fuel:omv', 'subcategory:fuel:lpg', 'subcategory:fuel:all'], $brands);
    }

    public function test_falls_back_to_openstreetmap_outside_the_local_data(): void
    {
        Cache::flush();
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => [['tags' => ['amenity' => 'fuel', 'brand' => 'Shell']]]])]);

        $brands = $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/subcategories?lat=48.85&lon=2.35&radius=3000')
            ->assertOk()
            ->json('subcategories.fuel');

        $this->assertEqualsCanonicalizing(['subcategory:fuel:shell', 'subcategory:fuel:all'], $brands);
    }

    public function test_geoapify_subcategories_are_limited_to_the_ones_found_nearby(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/*' => Http::response(['features' => [
                ['properties' => ['categories' => ['catering.restaurant', 'catering.restaurant.pizza']]],
                ['properties' => ['categories' => ['catering.restaurant.burger']]],
            ]]),
            '*' => Http::response(['elements' => []]),
        ]);

        $ids = $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/subcategories?lat=48.85&lon=2.35&radius=3000')
            ->assertOk()
            ->json('subcategories.restaurant');

        $this->assertEqualsCanonicalizing([
            'subcategory:restaurant:catering.restaurant.pizza',
            'subcategory:restaurant:catering.restaurant.burger',
        ], $ids);
    }

    public function test_subway_remains_available_when_geoapify_has_no_stations_but_transit_osm_can(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/*' => Http::response(['features' => []]),
            'overpass-api.de/*' => Http::response(['elements' => []]),
        ]);

        $subcategories = $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/subcategories?lat=44.43&lon=26.10&radius=3000')
            ->assertOk()
            ->json('subcategories.subway');

        $this->assertContains('subcategory:subway:public_transport.subway.entrance', $subcategories);
    }

    public function test_airports_are_available_when_geoapify_finds_them_in_range(): void
    {
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/*' => Http::response(['features' => [
                ['properties' => ['categories' => ['airport', 'airport.international']]],
            ]]),
            '*' => Http::response(['elements' => []]),
        ]);
        Cache::flush();

        $nearAirport = $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/subcategories?lat=44.5711&lon=26.085&radius=5000')
            ->assertOk()
            ->json('subcategories.airport');

        $this->assertContains('subcategory:airport:airport.international', $nearAirport);
    }

    public function test_airports_are_unavailable_when_geoapify_finds_none_in_range(): void
    {
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/*' => Http::response(['features' => []]),
            '*' => Http::response(['elements' => []]),
        ]);

        $outsideAirportRange = $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/subcategories?lat=44.5711&lon=26.085&radius=5000')
            ->assertOk()
            ->json('subcategories.airport');

        $this->assertSame([], $outsideAirportRange);
    }
}
