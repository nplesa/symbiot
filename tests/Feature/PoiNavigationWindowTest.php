<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PoiCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PoiNavigationWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_displays_all_poi_filters_in_a_responsive_grid_without_a_poi_list(): void
    {
        $this->withoutVite();

        $response = $this
            ->actingAs(User::factory()->create())
            ->get(route('app.home'));

        $response->assertOk();
        $response->assertSee('poi-loading-elapsed', false)
            ->assertSee('aria-labelledby="poiLoadingModalTitle"', false)
            ->assertSee('aria-describedby="poiLoadingModalDescription"', false);
        $response->assertSee('id="trainStatusModal"', false)
            ->assertSee('id="transitLinesList"', false)
            ->assertSee('id="transitModalResizeHandle"', false)
            ->assertSee('id="transitLinesEmpty"', false)
            ->assertSee('id="trainStatusOfficialLink"', false)
            ->assertSee('id="metroArrivalEstimate"', false);
        $response->assertSee('id="lodgingDetailsModal"', false)
            ->assertSee('id="lodgingDetailsList"', false)
            ->assertSee('id="lodgingDetailsMapLink"', false);
        $response->assertSee('id="map-tile-progress"', false)
            ->assertSee('id="map-tile-progress-count"', false)
            ->assertSee('id="map-tile-progress-bar"', false);
        $response->assertSee('name="location-mode"', false)
            ->assertSee('id="city-location-mode"', false)
            ->assertSee('id="city-location-form"', false)
            ->assertSee('id="city-location-radius"', false)
            ->assertSee('Caută un oraș oriunde în lume')
            ->assertSee('id="city-location-results"', false)
            ->assertSee('min="100" max="35000" step="100" value="5000"', false);
        $response->assertSee('progress-bar-animated', false);
        $this->assertDoesNotMatchRegularExpression(
            '/<input\b(?=[^>]*\bclass="[^"]*\blocation-category\b")(?=[^>]*\bchecked\b)[^>]*>/i',
            $response->getContent()
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input\b(?=[^>]*\bclass="[^"]*\blocation-subcategory\b")(?=[^>]*\bchecked\b)[^>]*>/i',
            $response->getContent()
        );

        foreach (array_keys(config('poi.categories')) as $type) {
            $response->assertSee('data-type="' . $type . '"', false);
        }

        $dom = new \DOMDocument;
        $previousLibxmlErrorMode = libxml_use_internal_errors(true);
        $dom->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlErrorMode);
        $xpath = new \DOMXPath($dom);
        $poiCategoryGrid = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " poi-category-filters ")]//div[contains(@class, "row-cols-lg-3")]')->item(0);
        $this->assertNotNull($poiCategoryGrid);
        $visibleCategoryLabels = [];
        foreach ($xpath->query('.//label[contains(@class, "poi-category-label")]', $poiCategoryGrid) as $label) {
            $visibleCategoryLabels[] = trim($label->textContent);
        }
        $expectedCategoryLabels = collect(config('poi.categories'))
            ->filter(fn (array $category, string $type): bool => $type === 'police' || $category['group'] !== 'Poliție')
            ->pluck('label')
            ->all();
        usort($expectedCategoryLabels, fn (string $left, string $right): int => strcasecmp(
            Str::ascii($left),
            Str::ascii($right)
        ));
        $this->assertSame($expectedCategoryLabels, $visibleCategoryLabels, 'POI category cards should be sorted alphabetically by their visible labels.');

        $policeCard = $xpath->query('.//div[contains(@class, "poi-category-group") and @data-type="police"]', $poiCategoryGrid)->item(0);
        $this->assertNotNull($policeCard);
        foreach (['train', 'pharmacy'] as $category) {
            $this->assertNotNull(
                $xpath->query('.//div[contains(@class, "poi-category-group") and @data-type="' . $category . '"]', $poiCategoryGrid)->item(0),
                "Expected {$category} in the same POI category grid as Police."
            );
        }
        $policeCheckbox = $xpath->query('.//input[contains(@class, "location-category")]', $policeCard)->item(0);
        $this->assertNotNull($policeCheckbox);
        $subcategoryButton = $xpath->query('.//button[@data-bs-toggle="collapse"]', $policeCard)->item(0);
        $this->assertNotNull($subcategoryButton);
        $this->assertSame('false', $subcategoryButton->getAttribute('aria-expanded'));
        $subcategoryPanel = $dom->getElementById($subcategoryButton->getAttribute('aria-controls'));
        $this->assertStringNotContainsString('show', $subcategoryPanel?->getAttribute('class') ?? '');
        $this->assertSame(
            1,
            $xpath->query('.//div[contains(@class, "poi-category-group") and @data-type="police"]', $poiCategoryGrid)->length,
            'Police should appear as a single standard category card.'
        );

        foreach ([
            'police_station' => 'Secții',
            'traffic_filters' => 'Filtre în trafic',
            'speed_limit' => 'Limite de viteză',
            'traffic_sign' => 'Indicatoare',
            'locality' => 'Localități',
            'speed_camera' => 'Camere de viteză',
            'control' => 'Puncte de control',
            'vignette_control' => 'Taxe / rovinietă',
        ] as $type => $label) {
            $subcategory = $xpath->query('.//input[@data-filter="subcategory:police:' . $type . '"]', $subcategoryPanel)->item(0);
            $this->assertNotNull($subcategory, "Expected {$label} as a Police subcategory.");
            $expectedType = collect(config('poi.osm_subcategories.police'))
                ->firstWhere('id', $type)['type'] ?? 'police';
            $this->assertSame($expectedType, $subcategory->getAttribute('data-type'));
            $this->assertSame('subcategory:police:' . $type, $subcategory->getAttribute('data-filter'));
            $this->assertStringContainsString($label, $subcategoryPanel->textContent);
        }
        $visiblePoliceSubcategories = [];
        foreach ($xpath->query('.//label[contains(@class, "form-check-label")]', $subcategoryPanel) as $label) {
            $visiblePoliceSubcategories[] = trim($label->textContent);
        }
        $expectedPoliceSubcategories = array_column(config('poi.osm_subcategories.police'), 'label');
        usort($expectedPoliceSubcategories, fn (string $left, string $right): int => strcasecmp(
            Str::ascii($left),
            Str::ascii($right)
        ));
        $this->assertSame($expectedPoliceSubcategories, $visiblePoliceSubcategories, 'Police subcategories should be sorted alphabetically by their visible labels.');

        $response->assertDontSee('auto_detect_location')
            ->assertDontSee('Activate stations auto-location')
            ->assertSee('poi-category-filters')
            ->assertSee('data-type="police"', false)
            ->assertSee('Poliție')
            ->assertSee('data-filter="subcategory:police:police_station"', false)
            ->assertSee('data-filter="subcategory:police:traffic_filters"', false)
            ->assertSee('data-filter="subcategory:police:speed_limit"', false)
            ->assertSee('data-filter="subcategory:police:control"', false)
            ->assertSee('data-filter="subcategory:police:traffic_sign"', false)
            ->assertSee('data-filter="subcategory:police:locality"', false)
            ->assertSee('data-filter="subcategory:police:speed_camera"', false)
            ->assertSee('data-filter="subcategory:police:vignette_control"', false)
            ->assertSee('data-filter="subcategory:restaurant:catering.restaurant.italian"', false)
            ->assertSee('data-filter="subcategory:bus:all"', false)
            ->assertSee('data-type="speed_camera"', false)
            ->assertSee('data-type="speed_limit"', false)
            ->assertSee('data-type="locality"', false)
            ->assertDontSee('id="police"', false)
            ->assertDontSee('mobility-poi-list', false)
            ->assertDontSee('location-element', false)
            ->assertDontSee('mobility-cards-container', false)
            ->assertDontSee('mobility-card-body', false);

        $response->assertSee('Selectează categoriile POI afișate pe hartă')
            ->assertSee('poi-category-filters');
    }

    public function test_home_poi_search_uses_only_selected_categories(): void
    {
        Http::fake([
            '*' => Http::response(['features' => []]),
        ]);

        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=airport,police')
            ->assertOk()
            ->assertExactJson([]);

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), 'api.geoapify.com')
                && ($query['categories'] ?? null) === 'airport,service.police';
        });
        Http::assertSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && $request->method() === 'GET'
            && str_contains($request['data'], 'aeroway'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && $request->method() === 'POST');
    }

    public function test_police_subcategory_filter_uses_its_own_poi_category(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'overpass-api.de/*' => Http::response([
                'elements' => [[
                    'type' => 'node',
                    'id' => 987,
                    'lat' => 45.65,
                    'lon' => 25.60,
                    'tags' => ['maxspeed' => '50', 'name' => 'Limită test'],
                ]],
            ]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/speed_limit?lat=45.65&lon=25.60&radius=5000&types=subcategory:police:speed_limit')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.type', 'speed_limit')
            ->assertJsonPath('0.name', 'Limită test');

        Http::assertSent(fn ($request) => str_contains($request['data'], 'nwr["maxspeed"]'));
    }

    public function test_police_station_subcategory_queries_only_police_stations(): void
    {
        Cache::flush();
        Http::fake([
            'overpass-api.de/*' => Http::response([
                'elements' => [[
                    'type' => 'node',
                    'id' => 988,
                    'lat' => 45.65,
                    'lon' => 25.60,
                    'tags' => ['amenity' => 'police', 'name' => 'Secția test'],
                ]],
            ]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/police?lat=45.65&lon=25.60&radius=5000&types=subcategory:police:police_station')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.type', 'police')
            ->assertJsonPath('0.name', 'Secția test');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['data'], 'nwr["amenity"="police"]')
            && ! str_contains($request['data'], '["police"="checkpoint"]'));
    }

    public function test_traffic_filter_subcategory_does_not_query_openstreetmap(): void
    {
        Cache::flush();
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/police?lat=45.65&lon=25.60&radius=5000&types=subcategory:police:traffic_filters')
            ->assertOk()
            ->assertExactJson([]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'overpass-api.de'));
    }

    public function test_traffic_filter_subcategory_includes_waze_police_alerts(): void
    {
        Cache::flush();
        config([
            'services.waze.url' => 'https://waze.test/api',
            'services.waze.key' => 'test-waze-key',
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'waze.test/api/alerts-and-jams')) {
                return Http::response([
                    'status' => 'OK',
                    'request_id' => 'test-request',
                    'parameters' => [],
                    'data' => [
                        'alerts' => [
                            [
                                'alert_id' => 'waze-police-1',
                                'type' => 'POLICE',
                                'subtype' => 'POLICE_VISIBLE',
                                'latitude' => 45.651,
                                'longitude' => 25.601,
                                'street' => 'Strada Test',
                                'city' => 'Brașov',
                            ],
                            [
                                'alert_id' => 'waze-hazard-1',
                                'type' => 'HAZARD',
                                'latitude' => 45.652,
                                'longitude' => 25.602,
                            ],
                        ],
                        'jams' => [],
                    ],
                ]);
            }

            return Http::response(['elements' => []]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/waze-traffic?lat=45.65&lon=25.60&radius=5000')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', 'waze/waze-police-1')
            ->assertJsonPath('0.name', 'Police visible')
            ->assertJsonPath('0.type', 'police')
            ->assertJsonPath('0.address.city', 'Brașov')
            ->assertJsonPath('0.details.provider', 'Waze');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'waze.test/api/alerts-and-jams')
            && $request->hasHeader('x-api-key', 'test-waze-key')
            && str_contains($request->url(), 'center=45.65%2C25.6')
            && str_contains($request->url(), 'radius=5'));
    }

    public function test_waze_api_is_not_called_for_other_police_subcategories(): void
    {
        Cache::flush();
        config(['services.waze.key' => 'test-waze-key']);
        Http::fake([
            'overpass-api.de/*' => Http::response(['elements' => []]),
            '*' => Http::response(['elements' => []]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/police?lat=45.65&lon=25.60&radius=5000&types=subcategory:police:police_station')
            ->assertOk()
            ->assertExactJson([]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openwebninja.com/waze'));
    }

    public function test_waze_forbidden_response_explains_key_and_api_access_requirements(): void
    {
        Cache::flush();
        config([
            'services.waze.url' => 'https://waze.test/api',
            'services.waze.key' => 'test-waze-key',
        ]);
        Http::fake([
            'waze.test/*' => Http::response(['error' => 'Forbidden'], 403),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/waze-traffic?lat=45.65&lon=25.60&radius=5000')
            ->assertInternalServerError()
            ->assertJsonPath(
                'error',
                'OpenWebNinja Waze API returned HTTP 403. Verifică WAZE_API_KEY și confirmă că API-ul Waze este activat în contul OpenWebNinja.'
            );
    }

    public function test_every_poi_category_has_its_own_named_endpoint(): void
    {
        foreach (array_keys(config('poi.categories')) as $category) {
            if (in_array($category, ['bus', 'train', 'subway', 'airport'], true)) {
                $this->assertSame(
                    '/api/transport/' . $category,
                    parse_url(route('app.api.transport.' . $category), PHP_URL_PATH)
                );

                continue;
            }

            $this->assertSame(
                '/api/poi/' . $category,
                parse_url(route('app.api.poi.' . $category), PHP_URL_PATH)
            );
        }
    }

    public function test_category_endpoint_only_fetches_the_selected_category_and_its_subcategory(): void
    {
        Cache::flush();
        Http::fake([
            'api.geoapify.com/*' => Http::response(['features' => []]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/restaurant?lat=45.65&lon=25.60&radius=5000&types=subcategory:restaurant:catering.restaurant.italian')
            ->assertOk()
            ->assertExactJson([]);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), 'api.geoapify.com')
                && ($query['categories'] ?? null) === 'catering.restaurant.italian';
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'overpass-api.de'));
    }

    public function test_lodging_poi_returns_available_details_for_the_map_modal(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/*' => Http::response(['features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [25.60, 45.65]],
                'properties' => [
                    'place_id' => 'lodging-1',
                    'name' => 'Pensiunea Exemplu',
                    'categories' => ['accommodation.guest_house'],
                    'formatted' => 'Strada Exemplu 10, Brașov',
                    'phone' => '+40 268 123 456',
                    'website' => 'https://example.test',
                    'opening_hours' => 'Mo-Su 08:00-22:00',
                    'stars' => '3',
                    'datasource' => [
                        'raw' => [
                            'rooms' => '8',
                            'check-in' => '14:00',
                        ],
                    ],
                ],
            ]]]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/lodging?lat=45.65&lon=25.60&radius=5000&types=lodging')
            ->assertOk()
            ->assertJsonPath('0.type', 'lodging')
            ->assertJsonPath('0.name', 'Pensiunea Exemplu')
            ->assertJsonPath('0.details.address', 'Strada Exemplu 10, Brașov')
            ->assertJsonPath('0.details.phone', '+40 268 123 456')
            ->assertJsonPath('0.details.website', 'https://example.test')
            ->assertJsonPath('0.details.opening_hours', 'Mo-Su 08:00-22:00')
            ->assertJsonPath('0.details.stars', '3')
            ->assertJsonPath('0.details.rooms', '8')
            ->assertJsonPath('0.details.check_in', '14:00');
    }

    public function test_home_poi_search_with_no_selected_categories_skips_provider_requests(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=')
            ->assertOk()
            ->assertExactJson([]);

        Http::assertNothingSent();
    }

    public function test_city_location_search_returns_the_geocoded_city_center(): void
    {
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/v1/geocode/search*' => Http::response([
                'results' => [[
                    'city' => 'București',
                    'country' => 'România',
                    'lat' => 44.4268,
                    'lon' => 26.1025,
                    'country_code' => 'ro',
                    'place_id' => 'bucharest',
                ]],
            ]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/location/city?city=Bucuresti')
            ->assertOk()
            ->assertJsonPath('results.0.name', 'București')
            ->assertJsonPath('results.0.label', 'București, România')
            ->assertJsonPath('results.0.lat', 44.4268)
            ->assertJsonPath('results.0.lon', 26.1025);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.geoapify.com/v1/geocode/search')
            && $request['text'] === 'Bucuresti'
            && $request['type'] === 'city'
            && ! isset($request['filter'])
            && $request['apiKey'] === 'test-key');
    }

    public function test_city_location_search_returns_multiple_countries_for_ambiguous_names(): void
    {
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/v1/geocode/search*' => Http::response([
                'results' => [
                    [
                        'place_id' => 'paris-fr',
                        'city' => 'Paris',
                        'state' => 'Île-de-France',
                        'country' => 'Franța',
                        'country_code' => 'fr',
                        'lat' => 48.8566,
                        'lon' => 2.3522,
                    ],
                    [
                        'place_id' => 'paris-us',
                        'city' => 'Paris',
                        'state' => 'Texas',
                        'country' => 'Statele Unite',
                        'country_code' => 'us',
                        'lat' => 33.6609,
                        'lon' => -95.5555,
                    ],
                ],
            ]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/location/city?city=Paris')
            ->assertOk()
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.label', 'Paris, Île-de-France, Franța')
            ->assertJsonPath('results.1.label', 'Paris, Texas, Statele Unite')
            ->assertJsonPath('results.1.country_code', 'us');
    }

    public function test_city_location_search_reports_unknown_city(): void
    {
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/v1/geocode/search*' => Http::response(['results' => []]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/location/city?city=OrasInexistent')
            ->assertNotFound()
            ->assertJsonPath('error', 'Localitatea nu a fost găsită. Încearcă să adaugi și țara.');
    }

    public function test_home_poi_search_uses_only_selected_geoapify_subcategory(): void
    {
        Http::fake([
            '*' => Http::response(['features' => []]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=subcategory:restaurant:catering.restaurant.italian')
            ->assertOk()
            ->assertExactJson([]);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), 'api.geoapify.com')
                && ($query['categories'] ?? null) === 'catering.restaurant.italian';
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'overpass-api.de'));
    }

    public function test_bus_stations_use_geoapify_and_fetch_route_relations_from_openstreetmap(): void
    {
        Http::fake([
            '*' => Http::response(['features' => []]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=bus')
            ->assertOk()
            ->assertExactJson([]);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.geoapify.com')
            && str_contains($request->url(), 'categories=public_transport.bus'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && $request->method() === 'GET'
            && str_contains($request['data'], 'highway')
            && str_contains($request['data'], 'bus_stop'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && $request->method() === 'POST');
    }

    public function test_bus_station_results_include_route_refs_from_openstreetmap_relations(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['features' => [[
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [25.6001, 45.6501]],
                    'properties' => [
                        'place_id' => 'bus-stop-1',
                        'name' => 'Gara',
                        'categories' => ['public_transport.bus'],
                    ],
                ]]]);
            }

            return Http::response(['elements' => [
                [
                    'type' => 'way',
                    'id' => 77,
                    'center' => ['lat' => 45.6501, 'lon' => 25.6001],
                    'tags' => ['public_transport' => 'stop_position'],
                ],
                [
                    'type' => 'relation',
                    'id' => 88,
                    'timestamp' => '2026-06-02T08:56:08Z',
                    'tags' => ['type' => 'route', 'route' => 'bus', 'ref' => '5---'],
                    'members' => [[
                        'type' => 'way',
                        'ref' => 77,
                        'role' => 'platform',
                        'geometry' => [
                            ['lat' => 45.6501, 'lon' => 25.6001],
                            ['lat' => 45.651, 'lon' => 25.601],
                        ],
                    ]],
                ],
            ]]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=bus')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Gara')
            ->assertJsonPath('0.routes.0.label', 'Autobuz 5')
            ->assertJsonPath('0.routes.0.id', 'relation/88')
            ->assertJsonPath('0.routes.0.updated_at', '2026-06-02T08:56:08Z')
            ->assertJsonPath('0.routes.0.status', 'listed_in_osm');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && str_contains($request['data'], '["public_transport"~"^(platform|stop_position|station)$"]')
            && str_contains($request['data'], 'rel(bn.stops)')
            && str_contains($request['data'], 'rel(bw.stops)')
            && str_contains($request['data'], '["route"~"^(bus|trolleybus)$"]'));
    }

    public function test_transit_route_geometry_is_loaded_on_demand_from_openstreetmap(): void
    {
        Http::fake([
            '*' => Http::response(['elements' => [
                [
                    'type' => 'relation',
                    'id' => 88,
                    'timestamp' => '2026-06-02T08:56:08Z',
                    'members' => [['type' => 'way', 'ref' => 77, 'role' => 'forward']],
                ],
                ['type' => 'way', 'id' => 77, 'nodes' => [1, 2]],
                ['type' => 'node', 'id' => 1, 'lat' => 45.6501, 'lon' => 25.6001],
                ['type' => 'node', 'id' => 2, 'lat' => 45.651, 'lon' => 25.601],
            ]]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transit-route/88')
            ->assertOk()
            ->assertJsonPath('updated_at', '2026-06-02T08:56:08Z')
            ->assertJsonPath('segments.0.1.0', 25.601);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.openstreetmap.org/api/0.6/relation/88/full.json'
            && $request->method() === 'GET');
    }

    public function test_incomplete_overpass_results_are_not_cached_as_missing_transit_routes(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['features' => [[
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [25.60, 45.65]],
                    'properties' => [
                        'place_id' => 'bus-stop-incomplete-routes',
                        'name' => 'Bus stop',
                        'categories' => ['public_transport.bus'],
                    ],
                ]]]);
            }

            return Http::response([
                'remark' => 'runtime error: Query timed out in "query" at line 1.',
                'elements' => [],
            ]);
        });

        $url = '/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=bus';
        $this->actingAs(User::factory()->create())
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('0.id', 'bus-stop-incomplete-routes')
            ->assertJsonPath('0.routes_available', false);
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('0.id', 'bus-stop-incomplete-routes')
            ->assertJsonPath('0.routes_available', false);

        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'overpass-api.de'));
        $this->assertSame(
            2,
            Http::recorded(fn ($request) => str_contains($request->url(), 'overpass-api.de'))->count()
        );
    }

    public function test_bus_stations_fall_back_to_openstreetmap_when_geoapify_has_no_results(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['features' => []], 503);
            }

            if ($request->method() === 'GET') {
                return Http::response(['elements' => [[
                    'type' => 'node',
                    'id' => 77,
                    'lat' => 45.6501,
                    'lon' => 25.6001,
                    'tags' => [
                        'public_transport' => 'platform',
                        'bus' => 'yes',
                        'name' => 'Stația Piața Centrală',
                    ],
                ]]]);
            }

            return Http::response(['elements' => [
                [
                    'type' => 'node',
                    'id' => 77,
                    'lat' => 45.6501,
                    'lon' => 25.6001,
                    'tags' => [
                        'public_transport' => 'platform',
                        'bus' => 'yes',
                        'name' => 'Stația Piața Centrală',
                    ],
                ],
                [
                    'type' => 'relation',
                    'id' => 88,
                    'timestamp' => '2026-06-02T08:56:08Z',
                    'tags' => ['type' => 'route', 'route' => 'bus', 'ref' => '5'],
                    'members' => [[
                        'type' => 'node',
                        'ref' => 77,
                        'role' => 'stop',
                    ]],
                ],
            ]]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=bus')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Stația Piața Centrală')
            ->assertJsonPath('0.type', 'bus')
            ->assertJsonPath('0.routes.0.label', 'Autobuz 5');
    }

    public function test_bus_filter_reports_failure_when_geoapify_and_openstreetmap_fallback_both_fail(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['features' => []], 503);
            }

            return Http::response([], 503);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport/bus?lat=45.65&lon=25.60&radius=10000')
            ->assertInternalServerError()
            ->assertJsonPath('error', 'OpenStreetMap POI service returned HTTP 503.');

        $this->assertSame(
            1,
            Http::recorded(fn ($request) => str_contains($request->url(), 'overpass-api.de'))->count()
        );
    }

    public function test_bus_filter_accepts_the_configured_ten_kilometer_location_radius(): void
    {
        Cache::flush();
        Http::fake([
            '*' => Http::response(['features' => []]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport/bus?lat=45.65&lon=25.60&radius=10000')
            ->assertOk();
    }

    public function test_transit_stations_include_their_mode_specific_routes(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            $points = [
                ['bus', 'public_transport.bus', 25.60, 45.65],
                ['subway', 'public_transport.subway', 25.61, 45.65],
                ['train', 'public_transport.train', 25.62, 45.65],
                ['airport', 'airport', 25.65, 45.65],
            ];

            if (str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['features' => array_map(
                    fn (array $point, int $index): array => [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [$point[2], $point[3]]],
                        'properties' => [
                            'place_id' => 'transit-stop-' . $index,
                            'name' => ucfirst($point[0]) . ' stop',
                            'categories' => [$point[1]],
                        ],
                    ],
                    $points,
                    array_keys($points)
                )]);
            }

            $stops = [
                ['node', 77, 25.60, ['highway' => 'bus_stop']],
                ['node', 78, 25.61, ['public_transport' => 'platform']],
                ['node', 79, 25.62, ['railway' => 'station']],
                ['way', 80, 25.65, ['aeroway' => 'aerodrome']],
            ];
            $stopLongitudes = [77 => 25.60, 78 => 25.61, 79 => 25.62, 80 => 25.65];
            $routeDefinitions = [
                ['bus', '5', 'node', 77],
                ['subway', 'M1', 'node', 78],
                ['subway', 'M2', 'node', 78],
                ['train', 'IR 15', 'node', 79],
                ['airline', 'RO', 'way', 80],
            ];
            $elements = [];

            foreach ($stops as [$elementType, $id, $lon, $tags]) {
                $elements[] = $elementType === 'node'
                    ? ['type' => $elementType, 'id' => $id, 'lat' => 45.65, 'lon' => $lon, 'tags' => $tags]
                    : ['type' => $elementType, 'id' => $id, 'center' => ['lat' => 45.65, 'lon' => $lon], 'tags' => $tags];
            }
            foreach ($routeDefinitions as $index => [$mode, $ref, $memberType, $memberId]) {
                $elements[] = [
                    'type' => 'relation',
                    'id' => 90 + $index,
                    'timestamp' => '2026-06-02T08:56:08Z',
                    'tags' => ['type' => 'route', 'route' => $mode, 'ref' => $ref],
                    'members' => [[
                        'type' => $memberType,
                        'ref' => $memberId,
                        'role' => 'stop',
                        'geometry' => [
                            ['lat' => 45.65, 'lon' => $stopLongitudes[$memberId]],
                            ['lat' => 45.651, 'lon' => $stopLongitudes[$memberId] + 0.001],
                        ],
                    ]],
                ];
            }

            return Http::response(['elements' => $elements]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=bus,subway,train,airport')
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('0.routes.0.label', 'Autobuz 5')
            ->assertJsonPath('0.routes_available', true)
            ->assertJsonPath('0.routes.0.updated_at', '2026-06-02T08:56:08Z')
            ->assertJsonPath('1.routes.0.label', 'Metrou M1')
            ->assertJsonPath('1.routes.1.label', 'Metrou M2')
            ->assertJsonPath('2.routes.0.label', 'Tren IR 15')
            ->assertJsonPath('3.routes.0.label', 'Companie aeriană RO');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && $request->method() === 'POST'
            && str_contains($request['data'], '^(bus|trolleybus|subway|train|light_rail|tram|monorail|funicular|airline)$')
            && str_contains($request['data'], 'around:5500,45.65000,25.60000')
            && substr_count($request['data'], 'nwr(around:5500,') === 8
            && str_contains($request['data'], '.routes out meta;.routes out body;'));
    }

    public function test_harman_station_includes_its_osm_train_routes_and_directions(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['features' => [[
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [25.6871262, 45.6980871]],
                    'properties' => [
                        'place_id' => 'harman-train-station',
                        'name' => 'Hărman',
                        'categories' => ['public_transport.train'],
                    ],
                ]]]);
            }

            return Http::response(['elements' => [
                [
                    'type' => 'node',
                    'id' => 2088363038,
                    'lat' => 45.6980871,
                    'lon' => 25.6871262,
                    'tags' => ['railway' => 'station', 'public_transport' => 'station'],
                ],
                [
                    'type' => 'node',
                    'id' => 8935426208,
                    'lat' => 45.6983467,
                    'lon' => 25.6868151,
                    'tags' => ['railway' => 'stop', 'public_transport' => 'stop_position'],
                ],
                [
                    'type' => 'node',
                    'id' => 8935426209,
                    'lat' => 45.6983876,
                    'lon' => 25.6867916,
                    'tags' => ['railway' => 'stop', 'public_transport' => 'stop_position'],
                ],
                [
                    'type' => 'node',
                    'id' => 7195987990,
                    'lat' => 45.6982685,
                    'lon' => 25.6869137,
                    'tags' => ['railway' => 'stop', 'public_transport' => 'stop_position'],
                ],
                [
                    'type' => 'relation',
                    'id' => 12999012,
                    'timestamp' => '2026-01-03T11:04:30Z',
                    'tags' => [
                        'type' => 'route',
                        'route' => 'train',
                        'ref' => 'R 16360',
                        'from' => 'Sfântu Gheorghe',
                        'to' => 'Brașov',
                    ],
                    'members' => [
                        ['type' => 'node', 'ref' => 8935426208, 'role' => 'stop'],
                    ],
                ],
                [
                    'type' => 'relation',
                    'id' => 12997277,
                    'timestamp' => '2026-01-17T18:23:05Z',
                    'tags' => [
                        'type' => 'route',
                        'route' => 'train',
                        'ref' => 'R 16361',
                        'from' => 'Brașov',
                        'to' => 'Brețcu',
                    ],
                    'members' => [
                        ['type' => 'node', 'ref' => 8935426209, 'role' => 'stop'],
                        ['type' => 'node', 'ref' => 7195987990, 'role' => 'stop'],
                    ],
                ],
            ]]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.6980871&lon=25.6871262&radius=5000&types=train')
            ->assertOk()
            ->assertJsonPath('0.name', 'Hărman')
            ->assertJsonPath('0.routes.0.label', 'Tren R 16360')
            ->assertJsonPath('0.routes.0.direction', 'Sfântu Gheorghe → Brașov')
            ->assertJsonPath('0.routes.1.label', 'Tren R 16361')
            ->assertJsonPath('0.routes.1.direction', 'Brașov → Brețcu');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && str_contains($request['data'], 'railway')
            && str_contains($request['data'], '["railway"="stop"]'));
    }

    public function test_harman_train_routes_are_recovered_from_osm_when_overpass_omits_them(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['features' => [[
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [25.6871262, 45.6980871]],
                    'properties' => [
                        'place_id' => 'harman-train-station',
                        'name' => 'Hărman',
                        'categories' => ['public_transport.train'],
                    ],
                ]]]);
            }

            if (str_contains($request->url(), 'overpass-api.de')) {
                return Http::response(['elements' => [
                    [
                        'type' => 'node',
                        'id' => 8935426208,
                        'lat' => 45.6983467,
                        'lon' => 25.6868151,
                        'tags' => ['railway' => 'stop', 'public_transport' => 'stop_position'],
                    ],
                    [
                        'type' => 'node',
                        'id' => 8935426209,
                        'lat' => 45.6983876,
                        'lon' => 25.6867916,
                        'tags' => ['railway' => 'stop', 'public_transport' => 'stop_position'],
                    ],
                    [
                        'type' => 'node',
                        'id' => 7195987990,
                        'lat' => 45.6982685,
                        'lon' => 25.6869137,
                        'tags' => ['railway' => 'stop', 'public_transport' => 'stop_position'],
                    ],
                ]]);
            }

            $stopId = (int) basename(str_replace('/relations.json', '', $request->url()));
            $routesByStop = [
                8935426208 => [
                    12999012,
                    'R 16360',
                    'Sfântu Gheorghe',
                    'Brașov',
                    '2026-01-03T11:04:30Z',
                ],
                8935426209 => [
                    12997277,
                    'R 16361',
                    'Brașov',
                    'Brețcu',
                    '2026-01-17T18:23:05Z',
                ],
                7195987990 => [
                    12997277,
                    'R 16361',
                    'Brașov',
                    'Brețcu',
                    '2026-01-17T18:23:05Z',
                ],
            ];
            if (! isset($routesByStop[$stopId])) {
                return Http::response(['elements' => []]);
            }

            [$id, $ref, $from, $to, $timestamp] = $routesByStop[$stopId];

            return Http::response(['elements' => [[
                'type' => 'relation',
                'id' => $id,
                'timestamp' => $timestamp,
                'tags' => [
                    'type' => 'route',
                    'route' => 'train',
                    'ref' => $ref,
                    'from' => $from,
                    'to' => $to,
                ],
                'members' => [
                    ['type' => 'node', 'ref' => $stopId, 'role' => 'stop'],
                ],
            ]]]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.6980871&lon=25.6871262&radius=5000&types=train')
            ->assertOk()
            ->assertJsonPath('0.routes.0.label', 'Tren R 16360')
            ->assertJsonPath('0.routes.0.direction', 'Sfântu Gheorghe → Brașov')
            ->assertJsonPath('0.routes.1.label', 'Tren R 16361')
            ->assertJsonPath('0.routes.1.direction', 'Brașov → Brețcu');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openstreetmap.org/api/0.6/node/8935426208/relations.json'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openstreetmap.org/api/0.6/node/8935426209/relations.json'));
    }

    public function test_train_endpoint_returns_stations_without_blocking_on_published_timetable_lookups(): void
    {
        Cache::flush();
        config([
            'services.rail_timetable.packages' => [
                ['package' => 'mers-tren-transferoviar-calatori-s-r-l', 'operator' => 'Transferoviar Călători'],
            ],
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['features' => [[
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [26.027174, 44.95368]],
                    'properties' => [
                        'place_id' => 'ploiesti-nord',
                        'name' => 'Ploiești Nord',
                        'categories' => ['public_transport.train'],
                    ],
                ]]]);
            }

            if (str_contains($request->url(), 'data.gov.ro/api/3/action/package_show')) {
                return Http::response(['success' => true, 'result' => [
                    'resources' => [[
                        'id' => 'tfc-current-timetable',
                        'url' => 'https://data.gov.ro/timetable.xml',
                        'created' => '2025-12-08T08:11:22Z',
                    ]],
                ]]);
            }

            if (str_contains($request->url(), 'data.gov.ro/timetable.xml')) {
                return Http::response(<<<'XML'
                    <?xml version="1.0" encoding="UTF-8"?>
                    <XmlIf>
                        <XmlMts>
                            <Mt MtValabilDeLa="20250101" MtValabilPinaLa="20991231" />
                        </XmlMts>
                        <Trenuri>
                            <Tren Numar="10241" CategorieTren="R">
                                <Trase>
                                    <Trasa>
                                        <ElementTrasa DenStaOrigine="Ploiesti Est Post 1" DenStaDestinatie="Ploiesti Nord Hm." OraP="20340" TipOprire="C" />
                                        <ElementTrasa DenStaOrigine="Ploiesti Nord Hm." DenStaDestinatie="Ploiesti Sud" OraP="20640" TipOprire="C" />
                                    </Trasa>
                                </Trase>
                                <RestrictiiTren>
                                    <CalendarTren DeLa="20250101" PinaLa="20991231" Tip="Da" Zile="287" />
                                </RestrictiiTren>
                            </Tren>
                        </Trenuri>
                    </XmlIf>
                    XML);
            }

            return Http::response(['elements' => []]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport/train?lat=44.95368&lon=26.027174&radius=5000&include_routes=false')
            ->assertOk()
            ->assertJsonPath('0.name', 'Ploiești Nord')
            ->assertJsonPath('0.routes', []);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'data.gov.ro'));
    }

    public function test_train_endpoint_skips_slow_osm_fallback_when_loading_stations_without_lines(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['features' => []]);
            }

            return Http::response(['elements' => []]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport/train?lat=44.4268&lon=26.1025&radius=5000&include_routes=false')
            ->assertOk()
            ->assertExactJson([]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'overpass-api.de'));
    }

    public function test_bucharest_subway_simulation_returns_the_m1_and_m2_lines_from_the_dedicated_controller(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['features' => [[
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [26.0864, 44.4522]],
                    'properties' => [
                        'place_id' => 'bucharest-piata-victoriei-metro',
                        'name' => 'Piața Victoriei',
                        'categories' => ['public_transport.subway'],
                    ],
                ]]]);
            }

            return Http::response(['elements' => [
                [
                    'type' => 'node',
                    'id' => 5001,
                    'lat' => 44.4522,
                    'lon' => 26.0864,
                    'tags' => ['public_transport' => 'station', 'railway' => 'station', 'station' => 'subway', 'subway' => 'yes'],
                ],
                [
                    'type' => 'relation',
                    'id' => 5002,
                    'timestamp' => '2026-08-12T10:00:00Z',
                    'tags' => ['type' => 'route', 'route' => 'subway', 'ref' => 'M1'],
                    'members' => [['type' => 'node', 'ref' => 5001, 'role' => 'stop']],
                ],
                [
                    'type' => 'relation',
                    'id' => 5003,
                    'timestamp' => '2026-08-12T10:00:00Z',
                    'tags' => ['type' => 'route', 'route' => 'subway', 'ref' => 'M2'],
                    'members' => [['type' => 'node', 'ref' => 5001, 'role' => 'stop']],
                ],
            ]]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport/subway?lat=44.4522&lon=26.0864&radius=5000&types=bus')
            ->assertOk()
            ->assertJsonPath('0.name', 'Piața Victoriei')
            ->assertJsonPath('0.routes.0.label', 'Metrou M1')
            ->assertJsonPath('0.routes.1.label', 'Metrou M2');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.geoapify.com')
            && str_contains($request->url(), 'public_transport.subway'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && $request->method() === 'POST'
            && str_contains($request['data'], '["subway"="yes"]')
            && str_contains($request['data'], '["route"~"^(subway)$"]'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.geoapify.com')
            && str_contains($request->url(), 'public_transport.bus'));
    }

    public function test_train_only_lookup_queries_rail_stops_without_bus_or_airport_stops(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['features' => [[
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [25.60, 45.65]],
                    'properties' => [
                        'place_id' => 'train-stop',
                        'name' => 'Train stop',
                        'categories' => ['public_transport.train'],
                    ],
                ]]]);
            }

            return Http::response(['elements' => []]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=train,subway')
            ->assertOk()
            ->assertJsonPath('0.routes', [])
            ->assertJsonPath('0.routes_available', true);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && $request->method() === 'POST'
            && str_contains($request['data'], 'railway')
            && ! str_contains($request['data'], 'highway')
            && ! str_contains($request['data'], 'aeroway')
            && str_contains($request['data'], 'train|light_rail|tram|monorail|funicular|subway'));
    }

    public function test_transit_results_report_when_openstreetmap_route_lookup_fails(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.geoapify.com')) {
                return Http::response(['features' => [[
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [25.60, 45.65]],
                    'properties' => [
                        'place_id' => 'subway-stop',
                        'name' => 'Subway stop',
                        'categories' => ['public_transport.subway'],
                    ],
                ]]]);
            }

            return Http::response([], 503);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=subway')
            ->assertOk()
            ->assertJsonPath('0.routes', [])
            ->assertJsonPath('0.routes_available', false);
    }

    public function test_geoapify_subcategory_requests_are_split_at_the_provider_category_limit(): void
    {
        $filters = app(PoiCatalog::class)->filters();
        $subcategories = collect($filters)
            ->filter(fn (array $filter, string $id): bool => str_starts_with($id, 'subcategory:')
                && $filter['geoapify'] !== []
                && $filter['osm'] === [])
            ->keys()
            ->values();
        $this->assertGreaterThan(100, $subcategories->count());

        $batchSizes = [];
        Http::fake(function ($request) use (&$batchSizes) {
            if (str_contains($request->url(), 'api.geoapify.com')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $batchSizes[] = count(explode(',', $query['categories'] ?? ''));
            }

            return Http::response(['features' => []]);
        });

        $query = http_build_query([
            'lat' => 45.65,
            'lon' => 25.60,
            'radius' => 5000,
            'types' => $subcategories->implode(','),
        ]);
        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?' . $query)
            ->assertOk()
            ->assertExactJson([]);

        $expectedRequests = (int) ceil($subcategories->count() / 100);
        $this->assertCount($expectedRequests, $batchSizes);
        $this->assertLessThanOrEqual(100, max($batchSizes));
    }

    public function test_home_poi_search_uses_only_selected_police_group_subcategory(): void
    {
        Http::fake([
            '*' => Http::response(['elements' => [
                [
                    'type' => 'node',
                    'id' => 43,
                    'lat' => 45.6505,
                    'lon' => 25.6005,
                    'tags' => ['enforcement' => 'maxspeed'],
                ],
            ]]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=subcategory:police:control')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', 'node/43')
            ->assertJsonPath('0.type', 'control');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && str_contains($request['data'], 'nwr["enforcement"]')
            && ! str_contains($request['data'], '["amenity"="police"]'));
    }

    public function test_home_poi_search_batches_only_openstreetmap_police_subcategories(): void
    {
        Cache::flush();
        Http::fake([
            'overpass-api.de/*' => Http::response(['elements' => []]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=subcategory:police:speed_limit,subcategory:police:locality,subcategory:police:traffic_filters')
            ->assertOk()
            ->assertExactJson([]);

        $this->assertSame(
            1,
            Http::recorded(fn ($request) => str_contains($request->url(), 'overpass-api.de'))->count()
        );
        Http::assertSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && str_contains($request['data'], 'nwr["maxspeed"]')
            && str_contains($request['data'], 'nwr["place"~"^(city|town|village|hamlet)$"]')
            && ! str_contains($request['data'], 'nwr["police"="checkpoint"]')
            && ! str_contains($request['data'], 'nwr["police"="traffic_police"]'));
    }

    public function test_home_poi_search_returns_selected_openstreetmap_categories(): void
    {
        Http::fake([
            '*' => Http::response(['elements' => [
                [
                    'type' => 'node',
                    'id' => 42,
                    'lat' => 45.6505,
                    'lon' => 25.6005,
                    'tags' => ['highway' => 'speed_camera'],
                ],
            ]]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transport-nearby?lat=45.65&lon=25.60&radius=5000&types=speed_camera')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', 'node/42')
            ->assertJsonPath('0.type', 'speed_camera')
            ->assertJsonPath('0.name', 'Camere de viteză');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'overpass-api.de')
            && str_contains($request['data'], 'nwr["highway"="speed_camera"]'));
    }
}
