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

    public function test_small_radius_results_are_not_reused_for_a_point_400_m_away(): void
    {
        Cache::flush();
        $this->station(['brand' => 'Petrom'], 44.43, 26.10);
        $this->station(['brand' => 'OMV'], 44.4336, 26.10); // ~400 m north
        $user = User::factory()->create();

        $first = $this->actingAs($user)
            ->getJson('/api/poi/subcategories?lat=44.43&lon=26.10&radius=300')
            ->json('subcategories.fuel');
        $second = $this->actingAs($user)
            ->getJson('/api/poi/subcategories?lat=44.4336&lon=26.10&radius=300')
            ->json('subcategories.fuel');

        $this->assertContains('subcategory:fuel:petrom', $first);
        $this->assertNotContains('subcategory:fuel:omv', $first);
        $this->assertContains('subcategory:fuel:omv', $second);
        $this->assertNotContains('subcategory:fuel:petrom', $second);
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

    public function test_fuel_brands_are_unknown_outside_the_local_data_without_calling_overpass(): void
    {
        Cache::flush();
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => [['tags' => ['amenity' => 'fuel', 'brand' => 'Shell']]]])]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/subcategories?lat=48.85&lon=2.35&radius=3000')
            ->assertOk()
            ->assertJsonMissingPath('subcategories.fuel');

        // Fuel brands are never looked up on Overpass (only the OSM-only categories are counted there).
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && str_contains((string) ($request['data'] ?? ''), '"amenity"="fuel"'));
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

    /** @return array<string, mixed> */
    private function availability(float $lat = 45.6427, float $lon = 25.5887, int $radius = 5000): array
    {
        return $this->actingAs(User::factory()->create())
            ->getJson("/api/poi/subcategories?lat={$lat}&lon={$lon}&radius={$radius}")
            ->assertOk()
            ->json('subcategories');
    }

    public function test_single_subcategory_categories_are_checked_with_one_geoapify_result_each(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            // Only supermarkets exist around this point.
            $found = ($query['categories'] ?? '') === 'commercial.supermarket'
                ? [['properties' => ['categories' => ['commercial', 'commercial.supermarket']]]]
                : [];

            return Http::response(['features' => $found]);
        });

        $available = $this->availability();

        $this->assertSame(['subcategory:supermarket:all'], $available['supermarket']);
        $this->assertSame([], $available['pharmacy']);
        $this->assertSame([], $available['taxi']);
        $this->assertSame([], $available['subway']); // e.g. Brașov: no subway, the category is hidden
        Http::assertSent(fn ($request) => str_contains($request->url(), 'categories=commercial.supermarket')
            && str_contains($request->url(), 'limit=1'));
    }

    public function test_a_failed_geoapify_check_leaves_the_category_unknown_and_visible(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake(['api.geoapify.com/*' => Http::response('Too Many Requests', 429)]);

        $available = $this->availability();

        $this->assertArrayNotHasKey('supermarket', $available);
        $this->assertArrayNotHasKey('hospital', $available);
    }

    public function test_bus_stays_available_when_only_stored_gtfs_stops_exist_nearby(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['features' => []])]);
        $feed = TransitFeed::create(['slug' => 'local-bus', 'name' => 'Local Bus', 'static_hash' => 'imported', 'import_status' => 'imported']);
        DB::table('transit_stops')->insert(['feed_id' => $feed->id, 'stop_id' => 'b1', 'name' => 'Stop', 'lat' => 45.6430, 'lon' => 25.5890]);

        $this->assertSame(['subcategory:bus:all'], $this->availability()['bus']);
    }

    public function test_osm_only_categories_use_the_local_osm_extract_and_stay_unknown_outside_it(): void
    {
        Cache::flush();
        $this->point('speed_camera', ['highway' => 'speed_camera'], 45.6430, 25.5890);

        $inside = $this->availability();
        $this->assertSame(['subcategory:speed_camera:all'], $inside['speed_camera']);
        $this->assertSame([], $inside['vignette_control']);

        // No local OSM data around Paris and Overpass unreachable: unknown, so the categories stay visible.
        Http::fake(['*/api/interpreter*' => Http::response('busy', 504)]);
        $outside = $this->availability(48.85, 2.35);
        $this->assertArrayNotHasKey('speed_camera', $outside);
        $this->assertArrayNotHasKey('vignette_control', $outside);
    }

    public function test_outside_the_local_osm_extract_osm_only_categories_are_counted_on_overpass(): void
    {
        Cache::flush();
        $osmOnly = ['speed_camera', 'speed_limit', 'traffic_sign', 'vignette_control', 'control', 'locality'];
        // One "out count" per category, in the order of the query: only speed cameras and localities exist.
        Http::fake(['*/api/interpreter*' => Http::response(['elements' => array_map(
            fn (string $type): array => ['type' => 'count', 'id' => 0, 'tags' => ['total' => in_array($type, ['speed_camera', 'locality'], true) ? '4' : '0']],
            $osmOnly,
        )])]);

        $available = $this->availability(48.85, 2.35);

        $this->assertSame(['subcategory:speed_camera:all'], $available['speed_camera']);
        $this->assertSame(['subcategory:locality:all'], $available['locality']);
        $this->assertSame([], $available['vignette_control']);
        $this->assertSame([], $available['speed_limit']);
        // One count query for all categories, plus the aerodromes of the area (airport subcategories).
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains((string) $request['data'], '"aeroway"="aerodrome"'));
        Http::assertSent(fn ($request) => substr_count((string) $request['data'], 'out count;') === count($osmOnly)
            && str_contains((string) $request['data'], '(around:5000,48.850000,2.350000)'));
    }

    public function test_a_busy_overpass_server_falls_back_to_the_next_one_within_the_time_budget(): void
    {
        Cache::flush();
        config(['osm.overpass_endpoints' => ['https://busy.test/api/interpreter', 'https://free.test/api/interpreter']]);
        Http::fake([
            'busy.test/*' => Http::response('at capacity', 504),
            'free.test/*' => Http::response(['elements' => array_fill(0, 6, ['type' => 'count', 'tags' => ['total' => '2']])]),
        ]);

        $available = $this->availability(48.85, 2.35);

        $this->assertSame(['subcategory:speed_camera:all'], $available['speed_camera']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'free.test')
            && str_contains((string) $request['data'], '[timeout:3]'));
    }

    public function test_the_fast_phase_skips_overpass_and_lists_what_the_details_phase_completes(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/*' => Http::response(['features' => []]),
            '*/api/interpreter*' => fn ($request) => str_contains((string) $request['data'], 'aeroway')
                ? Http::response(['elements' => [['type' => 'way', 'id' => 1, 'center' => ['lat' => 48.86, 'lon' => 2.36], 'tags' => ['aeroway' => 'aerodrome', 'military' => 'airfield']]]])
                : Http::response(['elements' => array_fill(0, 6, ['type' => 'count', 'tags' => ['total' => '1']])]),
        ]);
        $this->actingAs(User::factory()->create());

        $fast = $this->getJson('/api/poi/subcategories?lat=48.85&lon=2.35&radius=5000&phase=fast')->assertOk();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'interpreter'));
        $this->assertContains('speed_camera', $fast->json('pending'));
        $this->assertContains('airport', $fast->json('pending'));
        $this->assertArrayNotHasKey('speed_camera', $fast->json('subcategories'));

        $osm = $this->getJson('/api/poi/subcategories?lat=48.85&lon=2.35&radius=5000&phase=details')->assertOk()->json('subcategories');
        $this->assertSame(['subcategory:speed_camera:all'], $osm['speed_camera']);
        $this->assertSame(['subcategory:airport:airport.military'], $osm['airport']);

        // Without a phase, both are combined (the airport ids are added to the Geoapify ones).
        $this->assertSame(['subcategory:airport:airport.military'], $this->availability(48.85, 2.35)['airport']);
    }

    public function test_an_incomplete_details_phase_is_asked_again_after_two_minutes(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('busy', 504)]);
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/poi/subcategories?lat=48.85&lon=2.35&radius=5000&phase=details')->assertOk()->assertExactJson(['subcategories' => []]);
        $sent = count(Http::recorded());

        $this->travel(3)->minutes();
        $this->getJson('/api/poi/subcategories?lat=48.85&lon=2.35&radius=5000&phase=details')->assertOk();
        $this->assertGreaterThan($sent, count(Http::recorded()));
    }

    public function test_dense_categories_get_an_existence_check_first_and_their_subcategories_in_the_background(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake(function ($request) {
            if (! str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['elements' => []]);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $features = match ($query['categories'] ?? '') {
                // Restaurants exist; parking does not.
                'catering.restaurant' => [['properties' => ['categories' => ['catering', 'catering.restaurant', 'catering.restaurant.afghan']]]],
                default => [],
            };

            return Http::response(['features' => $features]);
        });
        $this->actingAs(User::factory()->create());

        $fast = $this->getJson('/api/poi/subcategories?lat=48.85&lon=2.35&radius=5000&phase=fast')->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'categories=catering.restaurant&') && str_contains($request->url(), 'limit=1&'));
        // The dense categories are not detailed yet (the sparse airports and subway entrances are).
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'limit=500')
            && preg_match('/categories=(catering|parking|accommodation|tourism)/', $request->url()) === 1);
        $this->assertContains('restaurant', $fast->json('pending'));
        $this->assertArrayNotHasKey('restaurant', $fast->json('subcategories'), 'Unknown (all visible) until phase 2.');
        $this->assertNotContains('parking', $fast->json('pending'));
        $this->assertSame([], $fast->json('subcategories.parking'), 'No parking at all: hidden right away.');

        $details = $this->getJson('/api/poi/subcategories?lat=48.85&lon=2.35&radius=5000&phase=details')->assertOk()->json('subcategories');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'categories=catering.restaurant&') && str_contains($request->url(), 'limit=500'));
        $this->assertSame(['subcategory:restaurant:catering.restaurant.afghan'], $details['restaurant']);
    }
}
