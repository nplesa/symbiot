<?php

namespace Tests\Feature;

use App\Services\Poi\OsmAerodromes;
use App\Services\Poi\PoiSearch;
use App\Services\PoiSubcategoryAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OsmAerodromesTest extends TestCase
{
    use RefreshDatabase;

    private const BRASOV = [45.6427, 25.5887];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'osm.overpass_endpoints' => ['https://overpass.test/api/interpreter', 'https://overpass2.test/api/interpreter'],
            'osm.nominatim_url' => 'https://nominatim.test',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function elements(): array
    {
        return [
            ['type' => 'way', 'id' => 1, 'center' => ['lat' => 45.6977, 'lon' => 25.7797], 'tags' => ['aeroway' => 'aerodrome', 'name' => 'Aerodrom Prejmer']],
            ['type' => 'way', 'id' => 2, 'center' => ['lat' => 45.6500, 'lon' => 25.6000], 'tags' => ['aeroway' => 'aerodrome', 'name' => 'Baza militară', 'military' => 'airfield']],
            ['type' => 'node', 'id' => 3, 'lat' => 45.6600, 'lon' => 25.6100, 'tags' => ['aeroway' => 'aerodrome', 'name' => 'Club planorism', 'aerodrome:type' => 'gliding']],
            // Far outside any search radius of Brașov.
            ['type' => 'node', 'id' => 4, 'lat' => 44.5711, 'lon' => 26.0850, 'tags' => ['aeroway' => 'aerodrome', 'name' => 'Otopeni', 'iata' => 'OTP']],
        ];
    }

    public function test_aerodromes_are_sorted_into_the_airport_subcategories(): void
    {
        $this->assertSame('airport.military', OsmAerodromes::classify(['aeroway' => 'aerodrome', 'military' => 'airfield']));
        $this->assertSame('airport.military', OsmAerodromes::classify(['aeroway' => 'aerodrome', 'aerodrome:type' => 'military']));
        $this->assertSame('airport.gliding', OsmAerodromes::classify(['aeroway' => 'aerodrome', 'aerodrome:type' => 'gliding']));
        $this->assertSame('airport.international', OsmAerodromes::classify(['aeroway' => 'aerodrome', 'iata' => 'GHV']));
        $this->assertSame('airport.international', OsmAerodromes::classify(['aeroway' => 'aerodrome', 'aerodrome' => 'international']));
        $this->assertSame('airport.private', OsmAerodromes::classify(['aeroway' => 'aerodrome', 'access' => 'private']));
        $this->assertSame('airport.terminal', OsmAerodromes::classify(['aeroway' => 'terminal']));
        $this->assertSame('airport.airfield', OsmAerodromes::classify(['aeroway' => 'aerodrome', 'name' => 'Aerodrom Prejmer']));
    }

    public function test_the_area_is_fetched_once_and_kept_for_a_week(): void
    {
        Http::fake(['overpass.test/*' => Http::response(['elements' => $this->elements()])]);
        $service = app(OsmAerodromes::class);

        $near = $service->around(...[...self::BRASOV, 20000]);
        $this->assertEqualsCanonicalizing(['Aerodrom Prejmer', 'Baza militară', 'Club planorism'], array_column($near, 'name'));
        // A smaller radius and a nearby point reuse the same area.
        $this->assertSame(['Baza militară', 'Club planorism'], array_column($service->around(45.6450, 25.5900, 5000), 'name'));
        Http::assertSentCount(1);

        $this->travel(6)->days();
        $service->around(...[...self::BRASOV, 20000]);
        Http::assertSentCount(1);
    }

    public function test_nominatim_is_used_when_every_overpass_server_is_busy(): void
    {
        Http::fake([
            'overpass.test/*' => Http::response('at capacity', 504),
            'overpass2.test/*' => Http::response('at capacity', 504),
            'nominatim.test/*' => Http::response([
                ['category' => 'aeroway', 'type' => 'aerodrome', 'osm_type' => 'way', 'osm_id' => 1, 'lat' => '45.6976714', 'lon' => '25.7797231', 'name' => 'Aerodrom Prejmer', 'extratags' => []],
                ['category' => 'aeroway', 'type' => 'aerodrome', 'osm_type' => 'way', 'osm_id' => 2, 'lat' => '45.65', 'lon' => '25.60', 'name' => 'Baza', 'extratags' => ['military' => 'airfield']],
                // Other search hits (e.g. a street named after an aerodrome) are ignored.
                ['category' => 'highway', 'type' => 'residential', 'lat' => '45.66', 'lon' => '25.61', 'name' => 'Strada Aerodromului'],
            ]),
        ]);

        $found = app(OsmAerodromes::class)->around(...[...self::BRASOV, 20000]);

        $this->assertSame(['Aerodrom Prejmer' => 'airport.airfield', 'Baza' => 'airport.military'], array_column($found, 'subcategory', 'name'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://nominatim.test/search')
            && $request['q'] === 'aerodrome' && (string) $request['bounded'] === '1'
            && str_contains($request->header('User-Agent')[0] ?? '', 'Symbiot'));
        // Nominatim answers before the slower backup Overpass servers are tried.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'overpass2.test'));
    }

    public function test_unreachable_osm_is_unknown_and_not_retried_on_every_request(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $service = app(OsmAerodromes::class);

        $this->assertNull($service->around(...[...self::BRASOV, 20000]));
        $sent = count(Http::recorded());
        $this->assertNull($service->around(...[...self::BRASOV, 20000]));
        $this->assertCount($sent, Http::recorded());
    }

    public function test_the_poi_search_shows_only_the_selected_airport_subcategories(): void
    {
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'overpass.test/*' => Http::response(['elements' => $this->elements()]),
            'api.geoapify.com/*' => Http::response(['features' => []]),
        ]);
        $search = fn (array $filters): array => array_column(
            app(PoiSearch::class)->search(...[...self::BRASOV, 20000, $filters, false]),
            'name'
        );

        $this->assertSame(['Baza militară'], $search(['subcategory:airport:airport.military']));
        $this->assertEqualsCanonicalizing(['Aerodrom Prejmer', 'Club planorism'], $search(['subcategory:airport:airport.airfield', 'subcategory:airport:airport.gliding']));
        $this->assertEqualsCanonicalizing(['Aerodrom Prejmer', 'Baza militară', 'Club planorism'], $search(['airport']));
    }

    public function test_airport_subcategory_availability_includes_the_osm_aerodromes(): void
    {
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            '*/api/interpreter*' => fn ($request) => str_contains((string) $request['data'], 'aeroway')
                ? Http::response(['elements' => $this->elements()])
                : Http::response(['elements' => []]),
            // Geoapify has no airports here.
            'api.geoapify.com/*' => Http::response(['features' => []]),
        ]);

        $available = app(PoiSubcategoryAvailability::class)->around(...[...self::BRASOV, 20000]);

        $this->assertEqualsCanonicalizing(
            ['subcategory:airport:airport.airfield', 'subcategory:airport:airport.gliding', 'subcategory:airport:airport.military'],
            $available['airport']
        );
    }
}
