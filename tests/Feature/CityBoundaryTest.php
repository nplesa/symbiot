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

    /** An administrative unit as Geoapify "part-of" lists it: a rectangle [min lat, min lon, max lat, max lon]. */
    private function unit(string $name, ?string $city, array $box, int $adminLevel): array
    {
        [$minLat, $minLon, $maxLat, $maxLon] = $box;

        return [
            'type' => 'Feature',
            'properties' => array_filter([
                'name' => $name, 'city' => $city, 'place_id' => "pid-{$name}-{$adminLevel}", 'country_code' => 'xx',
                'datasource' => ['raw' => ['admin_level' => $adminLevel]],
            ], fn ($value) => $value !== null),
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [$minLon, $minLat], [$maxLon, $minLat], [$maxLon, $maxLat], [$minLon, $maxLat], [$minLon, $minLat],
            ]]],
        ];
    }

    public function test_brasov_is_the_municipality_not_the_county_nor_the_metropolitan_area(): void
    {
        // What Geoapify listed for the centre of Brașov (2026-11-19).
        Http::fake(['api.geoapify.com/v1/boundaries/part-of*' => Http::response(['features' => [
            $this->unit('Brașov', 'Brașov', [45.5, 25.504, 45.752, 25.689], 8),
            $this->unit('Zona Metropolitană Brașov', 'Zona Metropolitană Brașov', [45.422, 25.103, 45.884, 25.994], 7),
            $this->unit('Brașov', null, [45.381, 24.639, 46.19, 26.105], 4),
            $this->unit('România', null, [43.619, 20.262, 48.265, 30.045], 2),
        ]])]);

        // The search's name without diacritics, and its place id (which is not asked for).
        $this->boundary(45.6525, 25.6106, ['name' => 'Brasov', 'id' => 'search-place-id'])
            ->assertOk()
            ->assertJsonPath('feature.properties.datasource.raw.admin_level', 8);

        Http::assertSent(fn ($request) => isset($request['lat'], $request['lon']) && ! isset($request['id']));
        $this->assertSame(8, CityBoundary::query()->sole()->feature['properties']['datasource']['raw']['admin_level']);
    }

    public function test_berlin_is_the_whole_city_not_the_district_whose_city_is_berlin(): void
    {
        Http::fake(['api.geoapify.com/v1/boundaries/part-of*' => Http::response(['features' => [
            $this->unit('Mitte', 'Berlin', [52.504, 13.366, 52.54, 13.429], 10),
            $this->unit('Mitte', 'Berlin', [52.499, 13.302, 52.568, 13.429], 9),
            $this->unit('Berlin', 'Berlin', [52.338, 13.088, 52.676, 13.761], 4),
            $this->unit('Deutschland', null, [47.27, 5.866, 55.099, 15.042], 2),
        ]])]);

        $this->boundary(52.5174, 13.3951, ['name' => 'Berlin'])
            ->assertOk()
            ->assertJsonPath('feature.properties.name', 'Berlin')
            ->assertJsonPath('feature.properties.datasource.raw.admin_level', 4);

        // Another point of Berlin, already stored: the district is not taken for Berlin either.
        $this->boundary(52.45, 13.6, ['name' => 'Berlin'])->assertJsonPath('feature.properties.name', 'Berlin');
        Http::assertSentCount(1);
    }

    public function test_bucharest_is_the_city_not_its_sector(): void
    {
        Http::fake(['api.geoapify.com/v1/boundaries/part-of*' => Http::response(['features' => [
            $this->unit('Sector 1', 'București', [44.45, 26.0, 44.55, 26.12], 9),
            $this->unit('București', 'București', [44.33, 25.96, 44.55, 26.23], 4),
            $this->unit('România', null, [43.619, 20.262, 48.265, 30.045], 2),
        ]])]);

        $this->boundary(44.47, 26.08, ['name' => 'Bucuresti'])->assertOk()->assertJsonPath('feature.properties.name', 'București');
    }

    /** The same unit with its names in other languages (Geoapify's datasource.raw). */
    private function translated(array $unit, array $names): array
    {
        $unit['properties']['datasource']['raw'] = [...$unit['properties']['datasource']['raw'], ...$names];

        return $unit;
    }

    public function test_a_city_searched_by_its_romanian_name_is_found_by_its_translation(): void
    {
        // Searched as "Viena" (the search answers in Romanian); the boundary is named "Wien" (2026-11-19).
        Http::fake(['api.geoapify.com/v1/boundaries/part-of*' => Http::response(['features' => [
            $this->unit('Katastralgemeinde Innere Stadt', 'Viena', [48.2, 16.36, 48.217, 16.385], 10),
            $this->unit('Innere Stadt', 'Viena', [48.198, 16.355, 48.22, 16.39], 9),
            $this->translated($this->unit('Wien', 'Viena', [48.118, 16.182, 48.323, 16.578], 4), ['name:en' => 'Vienna', 'name:ro' => 'Viena']),
            $this->unit('Österreich', null, [46.372, 9.531, 49.021, 17.161], 2),
        ]])]);

        $this->boundary(48.2085, 16.3721, ['name' => 'Viena'])->assertOk()->assertJsonPath('feature.properties.name', 'Wien');
        // Then read from the database by the same Romanian name, without asking Geoapify again.
        $this->boundary(48.15, 16.3, ['name' => 'Viena'])->assertOk()->assertJsonPath('feature.properties.name', 'Wien');
        Http::assertSentCount(1);
    }

    public function test_london_is_greater_london_by_its_place_name(): void
    {
        Http::fake(['api.geoapify.com/v1/boundaries/part-of*' => Http::response(['features' => [
            $this->unit('City of Westminster', 'City of Westminster', [51.484, -0.216, 51.54, -0.111], 8),
            $this->translated($this->unit('Greater London', 'Londra', [51.286, -0.51, 51.692, 0.334], 5), ['_place_name:ro' => 'Londra']),
            $this->unit('England', null, [49.86, -6.42, 55.81, 1.77], 4),
        ]])]);

        $this->boundary(51.5074, -0.1278, ['name' => 'Londra'])->assertOk()->assertJsonPath('feature.properties.name', 'Greater London');
    }

    public function test_without_a_translation_the_largest_unit_of_the_searched_city_is_taken(): void
    {
        // Praga: no unit carries the Romanian name, all of them say city = "Praga"; the districts are smaller.
        Http::fake(['api.geoapify.com/v1/boundaries/part-of*' => Http::response(['features' => [
            $this->unit('SO Praha 1', 'Praga', [50.07, 14.4, 50.1, 14.44], 6),
            $this->unit('Praha 1', 'Praga', [50.07, 14.4, 50.1, 14.44], 9),
            $this->unit('obvod Praha 1', 'Praga', [50.07, 14.4, 50.1, 14.44], 5),
            $this->unit('Praha', 'Praga', [49.942, 14.224, 50.177, 14.707], 4),
            $this->unit('Česko', null, [48.55, 12.09, 51.06, 18.86], 2),
        ]])]);

        $this->boundary(50.0875, 14.4213, ['name' => 'Praga'])->assertOk()->assertJsonPath('feature.properties.name', 'Praha');
    }

    public function test_a_gps_point_takes_the_city_of_its_smallest_unit(): void
    {
        // In the centre of Vienna, without a name: the smallest unit says city = "Viena" → Wien.
        Http::fake(['api.geoapify.com/v1/boundaries/part-of*' => Http::response(['features' => [
            $this->unit('Katastralgemeinde Innere Stadt', 'Viena', [48.2, 16.36, 48.217, 16.385], 10),
            $this->unit('Innere Stadt', 'Viena', [48.198, 16.355, 48.22, 16.39], 9),
            $this->translated($this->unit('Wien', 'Viena', [48.118, 16.182, 48.323, 16.578], 4), ['name:ro' => 'Viena']),
        ]])]);

        $this->boundary(48.2085, 16.3721)->assertOk()->assertJsonPath('feature.properties.name', 'Wien');
    }

    public function test_only_a_metropolitan_area_or_a_county_is_no_city_boundary(): void
    {
        Http::fake(['api.geoapify.com/v1/boundaries/part-of*' => Http::response(['features' => [
            $this->unit('Zona Metropolitană Brașov', 'Zona Metropolitană Brașov', [45.422, 25.103, 45.884, 25.994], 7),
            $this->unit('Brașov', null, [45.381, 24.639, 46.19, 26.105], 4),
        ]])]);

        // Named Codlea: none of them is Codlea.
        $this->boundary(45.7, 25.45, ['name' => 'Codlea'])->assertNotFound();
        $this->assertSame(0, CityBoundary::query()->count());
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
