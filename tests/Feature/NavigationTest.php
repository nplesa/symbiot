<?php

namespace Tests\Feature;

use App\Livewire\Trasee\Index;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_route_opens_navigation(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $route = $this->makeRoute($user);

        Livewire::actingAs($user)->test(Index::class)
            ->call('selectRoute', $route->id)
            ->call('startRoute')
            ->assertRedirect(route('app.navigation.show', $route));

        $response = $this->actingAs($user)->get(route('app.navigation.show', $route));
        $response->assertOk()
            ->assertViewHas('route', fn ($selected) => $selected->is($route))
            ->assertSee('navigation-page')
            ->assertSee('Stop navigation')
            ->assertSee('<legend>Police</legend>', false)
            ->assertSee('data-poi-filter="police"', false)
            ->assertSee('data-poi-filter="speed_limit"', false)
            ->assertSee('data-poi-filter="control"', false)
            ->assertSee('data-poi-filter="traffic_sign"', false)
            ->assertSee('data-poi-filter="locality"', false)
            ->assertSee('data-poi-filter="speed_camera"', false)
            ->assertSee('data-poi-filter="vignette_control"', false);
    }

    public function test_navigation_requires_authentication_and_route_ownership(): void
    {
        $route = $this->makeRoute(User::factory()->create());

        foreach (['app.navigation.show', 'app.navigation.pois'] as $name) {
            $this->get(route($name, $route))->assertRedirect(route('login'));
        }

        $this->actingAs(User::factory()->create());
        foreach (['app.navigation.show', 'app.navigation.pois'] as $name) {
            $this->get(route($name, $route))->assertNotFound();
        }
    }

    public function test_route_pois_endpoint_is_available_to_owner(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => []])]);
        $user = User::factory()->create();
        $route = $this->makeRoute($user);

        $this->actingAs($user)->getJson(route('app.navigation.pois', $route))
            ->assertOk()->assertExactJson(['pois' => []]);
    }

    public function test_amenities_near_route_are_included_and_distant_ones_excluded(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => [
            ['type' => 'node', 'id' => 1, 'lon' => 25.60, 'lat' => 45.65, 'tags' => ['amenity' => 'fuel', 'name' => 'Benzinărie test']],
            ['type' => 'way', 'id' => 2, 'center' => ['lon' => 25.61, 'lat' => 45.66], 'tags' => ['amenity' => 'parking']],
            ['type' => 'node', 'id' => 3, 'lon' => 26.0, 'lat' => 46.0, 'tags' => ['amenity' => 'restaurant']],
        ]])]);
        $user = User::factory()->create();
        $route = $this->makeRoute($user);

        $this->actingAs($user)->getJson(route('app.navigation.pois', $route))
            ->assertOk()->assertJsonCount(2, 'pois')
            ->assertJsonPath('pois.0.type', 'fuel')
            ->assertJsonPath('pois.0.name', 'Benzinărie test')
            ->assertJsonPath('pois.1.type', 'parking');
    }

    public function test_supermarkets_near_route_are_included(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => [
            ['type' => 'node', 'id' => 1, 'lon' => 25.60, 'lat' => 45.65, 'tags' => ['shop' => 'supermarket', 'name' => 'Supermarket test']],
            ['type' => 'way', 'id' => 2, 'center' => ['lon' => 25.61, 'lat' => 45.66], 'tags' => ['shop' => 'supermarket']],
            ['type' => 'node', 'id' => 3, 'lon' => 26.0, 'lat' => 46.0, 'tags' => ['shop' => 'supermarket']],
        ]])]);
        $user = User::factory()->create();
        $route = $this->makeRoute($user);

        $this->actingAs($user)->getJson(route('app.navigation.pois', $route))
            ->assertOk()->assertJsonCount(2, 'pois')
            ->assertJsonPath('pois.0.type', 'supermarket')
            ->assertJsonPath('pois.0.name', 'Supermarket test')
            ->assertJsonPath('pois.1.name', 'Supermarket');
        Http::assertSent(fn ($request) => str_contains($request['data'], 'nwr["shop"="supermarket"]'));
    }

    public function test_long_routes_query_a_corridor_instead_of_the_whole_bounding_box(): void
    {
        // Bucharest -> Brasov, ~150 km, densely sampled with GPS-like jitter.
        $coordinates = [];
        for ($i = 0; $i <= 2000; $i++) {
            $ratio = $i / 2000;
            $coordinates[] = [26.10 - 0.50 * $ratio + ($i % 2 ? 0.00002 : -0.00002), 44.43 + 1.22 * $ratio];
        }
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => [
            // ~100 m east of the route, near its middle.
            ['type' => 'node', 'id' => 1, 'lon' => 25.8513, 'lat' => 45.04, 'tags' => ['amenity' => 'fuel']],
            // Targoviste: inside the route's overall bounding box, but ~35 km away from the route.
            ['type' => 'node', 'id' => 2, 'lon' => 25.45, 'lat' => 44.93, 'tags' => ['amenity' => 'fuel']],
        ]])]);
        $user = User::factory()->create();
        $route = Route::create([
            'user_id' => $user->id,
            'name' => 'Bucuresti - Brasov',
            'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
        ]);

        $this->actingAs($user)->getJson(route('app.navigation.pois', $route))
            ->assertOk()
            ->assertJsonCount(1, 'pois')
            ->assertJsonPath('pois.0.id', 'node/1');

        Http::assertSent(function ($request): bool {
            preg_match_all('/nwr\["shop"="supermarket"\]\(([^)]+)\)/', $request['data'], $matches);
            $boxes = array_map(fn (string $box): array => array_map('floatval', explode(',', $box)), $matches[1]);
            $widest = max(array_map(fn (array $box): float => $box[3] - $box[1], $boxes));

            // Several 20-30 km chunks, none spanning the route's full 0.5° longitude range.
            return $request->method() === 'POST' && count($boxes) >= 4 && $widest < 0.35;
        });
    }

    public function test_failed_overpass_lookup_is_cached_briefly_then_retried(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response('busy', 504)]);
        $user = User::factory()->create();
        $route = $this->makeRoute($user);

        $this->actingAs($user)->getJson(route('app.navigation.pois', $route))->assertOk()->assertExactJson(['pois' => []]);
        $sent = Http::recorded()->count();
        $this->actingAs($user)->getJson(route('app.navigation.pois', $route))->assertOk()->assertExactJson(['pois' => []]);
        $this->assertSame($sent, Http::recorded()->count(), 'A recent failure should be served from cache.');

        $this->travel(3)->minutes();
        $this->actingAs($user)->getJson(route('app.navigation.pois', $route))->assertOk();
        $this->assertGreaterThan($sent, Http::recorded()->count());
    }

    public function test_route_pois_endpoint_is_rate_limited(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => []])]);
        $user = User::factory()->create();
        $route = $this->makeRoute($user);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->getJson(route('app.navigation.pois', $route))->assertOk();
        }

        $this->actingAs($user)->getJson(route('app.navigation.pois', $route))->assertTooManyRequests();
    }

    public function test_invalid_route_coordinates_are_ignored(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => [
            ['type' => 'node', 'id' => 1, 'lon' => 25.60, 'lat' => 45.65, 'tags' => ['amenity' => 'fuel']],
        ]])]);
        $user = User::factory()->create();
        $route = Route::create([
            'user_id' => $user->id,
            'name' => 'Traseu cu puncte invalide',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[25.60, 45.65], ['x', 'y'], [999, 45], [25.61, 45.66]]],
        ]);

        $this->actingAs($user)->getJson(route('app.navigation.pois', $route))
            ->assertOk()->assertJsonCount(1, 'pois');
    }

    private function makeRoute(User $user): Route
    {
        return Route::create([
            'user_id' => $user->id,
            'name' => 'Traseu test',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[25.60, 45.65], [25.61, 45.66]]],
        ]);
    }
}
