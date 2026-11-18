<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PoiCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Parking: asked by its subcategories (the root is slow at Geoapify), cars ticked by default. */
class PoiParkingTest extends TestCase
{
    use RefreshDatabase;

    private const LEAVES = 'parking.bicycles,parking.cars,parking.motorcycle,parking.multistorey,parking.rooftop,parking.surface,parking.underground';

    public function test_the_whole_category_is_asked_by_its_subcategories_but_still_recognized_as_parking(): void
    {
        $catalog = app(PoiCatalog::class);

        $this->assertSame(explode(',', self::LEAVES), $catalog->geoapifyQuery('parking'));
        $this->assertSame(explode(',', self::LEAVES), $catalog->filters()['parking']['geoapify']);
        $this->assertSame('parking', $catalog->geoapifyType(['parking', 'parking.cars']));
        // Categories without a query list keep their own categories.
        $this->assertSame(['catering.restaurant'], $catalog->geoapifyQuery('restaurant'));
    }

    public function test_only_cars_are_marked_as_the_default_parking_subcategory(): void
    {
        $this->withoutVite();

        $html = $this->actingAs(User::factory()->create())->get(route('app.home'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-filter="subcategory:parking:parking\.cars"\s+data-default-subcategory/', $html);
        $this->assertSame(1, substr_count($html, 'data-default-subcategory'));
    }

    public function test_availability_and_search_ask_geoapify_for_the_parking_subcategories(): void
    {
        Cache::flush();
        config(['services.geoapify.key' => 'test-key']);
        Http::fake(['*' => Http::response(['features' => [], 'elements' => []])]);
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/poi/subcategories?lat=48.85&lon=2.35&radius=5000&phase=fast')->assertOk();
        $this->getJson('/api/poi/parking?lat=48.85&lon=2.35&radius=5000')->assertOk();

        $parkingRequests = collect(Http::recorded())
            ->map(fn (array $pair): array => $this->queryOf($pair[0]->url()))
            ->filter(fn (array $query): bool => str_contains($query['categories'] ?? '', 'parking'));
        $this->assertCount(2, $parkingRequests);
        $parkingRequests->each(fn (array $query) => $this->assertSame(self::LEAVES, $query['categories']));
    }

    /** @return array<string, string> */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
