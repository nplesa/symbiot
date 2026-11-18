<?php

namespace Tests\Feature;

use App\Models\CityBoundary;
use App\Models\User;
use App\Services\CityBoundaries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Whole city": a boundary is fetched from Geoapify once, then read from city_boundaries. */
class CityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        $this->actingAs(User::factory()->create());
    }

    /** A square city around (lat, lon), ±0.05°, with a Geoapify place id. */
    private function city(string $name, float $lat, float $lon): array
    {
        return [
            'type' => 'Feature',
            'properties' => ['name' => $name, 'city' => $name, 'place_id' => "pid-{$name}", 'country_code' => 'ro', 'categories' => ['administrative.city_level']],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [$lon - 0.05, $lat - 0.05], [$lon + 0.05, $lat - 0.05], [$lon + 0.05, $lat + 0.05], [$lon - 0.05, $lat + 0.05], [$lon - 0.05, $lat - 0.05],
            ]]],
        ];
    }

    /** Geoapify answers with the city around the asked point (a new city name per distinct point). */
    private function fakeGeoapify(): void
    {
        Http::fake(['api.geoapify.com/v1/boundaries/part-of*' => function ($request) {
            $lat = (float) ($request['lat'] ?? 45.64);
            $lon = (float) ($request['lon'] ?? 25.59);

            return Http::response(['features' => [$this->city("City {$lat}", $lat, $lon)]]);
        }]);
    }

    private function boundary(float $lat, float $lon, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/location/city-boundary?' . http_build_query(['lat' => $lat, 'lon' => $lon, ...$extra]));
    }

    public function test_a_city_is_fetched_once_then_read_from_the_database(): void
    {
        $this->fakeGeoapify();

        $this->boundary(45.64, 25.59)->assertOk()->assertJsonPath('feature.properties.name', 'City 45.64');
        $this->boundary(45.64, 25.59)->assertOk()->assertJsonPath('feature.properties.name', 'City 45.64');

        Http::assertSentCount(1);
        $stored = CityBoundary::query()->sole();
        $this->assertSame('pid-City 45.64', $stored->geoapify_id);
        $this->assertEqualsWithDelta(45.59, $stored->min_lat, 1e-6);
        $this->assertEqualsWithDelta(25.64, $stored->max_lon, 1e-6);
    }

    public function test_another_point_of_the_same_city_and_its_id_do_not_ask_geoapify_again(): void
    {
        $this->fakeGeoapify();
        $this->boundary(45.64, 25.59)->assertOk();

        $this->boundary(45.62, 25.61)->assertOk()->assertJsonPath('feature.properties.name', 'City 45.64');
        $this->getJson('/api/location/city-boundary?id=' . urlencode('pid-City 45.64'))->assertOk()->assertJsonPath('feature.properties.name', 'City 45.64');
        // Even after the cache is cleared (the old storage).
        Cache::flush();
        $this->boundary(45.66, 25.57)->assertOk();

        Http::assertSentCount(1);
    }

    public function test_a_point_outside_the_stored_cities_asks_geoapify(): void
    {
        $this->fakeGeoapify();
        $this->boundary(45.64, 25.59)->assertOk();

        $this->boundary(44.43, 26.10)->assertOk()->assertJsonPath('feature.properties.name', 'City 44.43');

        Http::assertSentCount(2);
        $this->assertSame(2, CityBoundary::query()->count());
    }

    public function test_only_new_cities_count_against_the_geoapify_limit(): void
    {
        $this->fakeGeoapify();
        for ($i = 0; $i < CityBoundaries::FETCHES_PER_MINUTE; $i++) {
            $this->boundary(40 + $i, 20)->assertOk();
        }

        // An eleventh new city in the same minute: refused, with Retry-After.
        $this->boundary(60, 20)->assertStatus(429)->assertHeader('Retry-After');
        // A stored city is still served.
        $this->boundary(40, 20)->assertOk();

        $this->travel(61)->seconds();
        $this->boundary(60, 20)->assertOk();
    }

    public function test_a_city_without_a_boundary_is_remembered_for_a_few_minutes_only(): void
    {
        Http::fake(['api.geoapify.com/*' => Http::response(['features' => []])]);

        $this->boundary(45.64, 25.59)->assertNotFound();
        $this->boundary(45.64, 25.59)->assertNotFound();
        Http::assertSentCount(1);

        $this->travel(CityBoundaries::NOT_FOUND_MINUTES + 1)->minutes();
        $this->boundary(45.64, 25.59)->assertNotFound();
        Http::assertSentCount(2);
        $this->assertSame(0, CityBoundary::query()->count());
    }

    public function test_an_unreachable_geoapify_is_an_error_not_a_missing_city(): void
    {
        $down = true;
        Http::fake(['api.geoapify.com/*' => function () use (&$down) {
            return $down
                ? Http::response('down', 503)
                : Http::response(['features' => [$this->city('Brașov', 45.64, 25.59)]]);
        }]);

        $this->boundary(45.64, 25.59)->assertStatus(502);

        // Not remembered as missing: the next request asks again and gets the city.
        $down = false;
        $this->boundary(45.64, 25.59)->assertOk()->assertJsonPath('feature.properties.name', 'Brașov');
    }

    public function test_the_named_city_wins_when_stored_boundaries_overlap(): void
    {
        $this->fakeGeoapify();
        // A larger unit already stored (e.g. the county) that contains the city.
        $county = $this->city('Județ', 45.64, 25.59);
        $county['geometry']['coordinates'] = [[[25.0, 45.0], [26.0, 45.0], [26.0, 46.0], [25.0, 46.0], [25.0, 45.0]]];
        CityBoundary::query()->create([
            'geoapify_id' => 'county', 'name' => 'Județ', 'feature' => $county,
            'min_lat' => 45.0, 'max_lat' => 46.0, 'min_lon' => 25.0, 'max_lon' => 26.0, 'fetched_at' => now(),
        ]);

        // The county is not the asked city: the city is fetched.
        $this->boundary(45.64, 25.59, ['name' => 'City 45.64'])->assertOk()->assertJsonPath('feature.properties.name', 'City 45.64');
        Http::assertSentCount(1);

        // Both contain the point: the asked name, else the smallest.
        $this->boundary(45.645, 25.595, ['name' => 'City 45.64'])->assertJsonPath('feature.properties.name', 'City 45.64');
        $this->boundary(45.645, 25.595, ['name' => 'Județ'])->assertJsonPath('feature.properties.name', 'Județ');
        $this->boundary(45.645, 25.595)->assertJsonPath('feature.properties.name', 'City 45.64');
        Http::assertSentCount(1);
    }

    public function test_points_in_a_hole_are_outside_the_city(): void
    {
        $withHole = ['type' => 'Polygon', 'coordinates' => [
            [[25, 45], [26, 45], [26, 46], [25, 46], [25, 45]],
            [[25.4, 45.4], [25.6, 45.4], [25.6, 45.6], [25.4, 45.6], [25.4, 45.4]],
        ]];

        $this->assertTrue(CityBoundaries::contains($withHole, 25.2, 45.2));
        $this->assertFalse(CityBoundaries::contains($withHole, 25.5, 45.5));
        $this->assertFalse(CityBoundaries::contains($withHole, 27, 45.5));
        $this->assertTrue(CityBoundaries::contains(['type' => 'MultiPolygon', 'coordinates' => [$withHole['coordinates']]], 25.2, 45.2));
    }
}
