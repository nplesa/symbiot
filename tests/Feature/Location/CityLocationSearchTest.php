<?php

namespace Tests\Feature\Location;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CityLocationSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_city_search_returns_international_candidates_and_does_not_restrict_country(): void
    {
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/v1/geocode/search*' => Http::response([
                'results' => [
                    [
                        'place_id' => 'paris-fr',
                        'city' => 'Paris',
                        'state' => 'Île-de-France',
                        'country' => 'France',
                        'country_code' => 'fr',
                        'lat' => 48.8566,
                        'lon' => 2.3522,
                    ],
                    [
                        'place_id' => 'paris-us',
                        'city' => 'Paris',
                        'state' => 'Texas',
                        'country' => 'United States',
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
            ->assertJsonPath('results.0.label', 'Paris, Île-de-France, France')
            ->assertJsonPath('results.1.label', 'Paris, Texas, United States')
            ->assertJsonPath('results.1.country_code', 'us');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.geoapify.com/v1/geocode/search')
            && $request['text'] === 'Paris'
            && $request['type'] === 'city'
            && ! isset($request['filter'])
            && $request['apiKey'] === 'test-key');
    }

    public function test_city_search_reports_when_no_matching_city_exists(): void
    {
        config(['services.geoapify.key' => 'test-key']);
        Http::fake([
            'api.geoapify.com/v1/geocode/search*' => Http::response(['results' => []]),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/location/city?city=NoSuchPlace')
            ->assertNotFound()
            ->assertJsonPath('error', 'No matching city was found. Try adding the country name.');
    }
}
