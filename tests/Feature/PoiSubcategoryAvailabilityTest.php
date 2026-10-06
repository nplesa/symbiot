<?php

namespace Tests\Feature;

use App\Models\User;
use App\Transit\Models\TransitFeed;
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

    private function point(string $type, array $tags, float $lat, float $lon): void
    {
        DB::table('transport_points')->insert([
            'name' => $tags['name'] ?? ucfirst($type), 'type' => $type, 'source' => 'openstreetmap',
            'osm_type' => 'n', 'osm_id' => (string) random_int(1, 999999), 'tags' => json_encode($tags),
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

    public function test_subway_is_unavailable_when_no_nearby_source_reports_it(): void
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

        $this->assertSame([], $subcategories);
    }

    public function test_subway_is_available_when_a_nearby_gtfs_route_is_a_subway(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/*' => Http::response(['features' => []]),
            '*' => Http::response(['elements' => []]),
        ]);
        $feed = TransitFeed::create([
            'slug' => 'nearby-subway',
            'name' => 'Nearby Subway',
            'static_hash' => 'imported',
            'import_status' => 'imported',
            'min_lat' => 44.9,
            'max_lat' => 45.0,
            'min_lon' => 25.4,
            'max_lon' => 25.5,
        ]);
        DB::table('transit_stops')->insert([
            'feed_id' => $feed->id, 'stop_id' => 'metro-stop', 'name' => 'Metro', 'lat' => 44.9268, 'lon' => 25.4627,
        ]);
        DB::table('transit_routes')->insert([
            'feed_id' => $feed->id, 'route_id' => 'M1', 'route_type' => 1,
        ]);
        DB::table('transit_trips')->insert([
            'feed_id' => $feed->id, 'trip_id' => 'metro-trip', 'route_id' => 'M1', 'service_id' => 'daily',
        ]);
        DB::table('transit_stop_times')->insert([
            'feed_id' => $feed->id, 'trip_id' => 'metro-trip', 'stop_id' => 'metro-stop', 'stop_sequence' => 1,
        ]);

        $subcategories = $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/subcategories?lat=44.9267&lon=25.4626&radius=3000')
            ->assertOk()
            ->json('subcategories.subway');

        $this->assertContains('subcategory:subway:public_transport.subway.entrance', $subcategories);
    }

    public function test_local_cafes_keep_the_category_available_when_geoapify_has_no_subtype_match(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/*' => Http::response(['features' => []]),
            '*' => Http::response(['elements' => []]),
        ]);
        $this->point('cafe', ['amenity' => 'cafe', 'name' => 'Cafe din centru'], 44.43, 26.10);

        $available = $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/subcategories?lat=44.43&lon=26.10&radius=3000')
            ->assertOk()
            ->json('subcategories.cafe');

        $this->assertContains('cafe', $available);
    }

    public function test_local_cafes_outside_the_radius_do_not_enable_the_category(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/*' => Http::response(['features' => []]),
            '*' => Http::response(['elements' => []]),
        ]);
        $this->point('cafe', ['amenity' => 'cafe', 'name' => 'Cafe departe'], 44.50, 26.10);

        $available = $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/subcategories?lat=44.43&lon=26.10&radius=1000')
            ->assertOk()
            ->json('subcategories.cafe');

        $this->assertSame([], $available);
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
