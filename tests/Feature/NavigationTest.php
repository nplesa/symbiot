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

        $this->actingAs($user)->get(route('app.navigation.show', $route))
            ->assertOk()
            ->assertViewHas('route', fn ($selected) => $selected->is($route))
            ->assertSee('navigation-page')
            ->assertSee('Oprește navigația');
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

    private function makeRoute(User $user): Route
    {
        return Route::create([
            'user_id' => $user->id,
            'name' => 'Traseu test',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[25.60, 45.65], [25.61, 45.66]]],
        ]);
    }
}
