<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteNamesTest extends TestCase
{
    public function test_every_web_controller_route_has_its_own_name(): void
    {
        $unnamed = [];
        $namedUris = collect(Route::getRoutes()->getRoutesByName())->map->uri()->flip();
        foreach (Route::getRoutes() as $route) {
            // Auth::routes() names only the GET of login/register/password.confirm; the POST shares its URL.
            if ($route->getName() === null && $namedUris->has($route->uri())) {
                continue;
            }
            // Web routes of the app's own controllers; package routes (Livewire, Sanctum, storage)
            // and the mobile API (routes/api.php, unnamed by design) are out of scope.
            if (! str_starts_with($route->getActionName(), 'App\\')
                || ! in_array('web', $route->gatherMiddleware(), true)) {
                continue;
            }

            $name = $route->getName();
            // A name ending in "." is only a group prefix (e.g. "app."), not a name of its own.
            if ($name === null || str_ends_with($name, '.')) {
                $unnamed[] = implode('|', $route->methods()) . ' ' . $route->uri();
            }
        }

        $this->assertSame([], $unnamed);
    }

    public function test_the_previously_unnamed_routes_keep_their_urls(): void
    {
        $this->assertSame('/api/location/city', route('app.api.location.city', absolute: false));
        $this->assertSame('/api/location/city-boundary', route('app.api.location.city-boundary', absolute: false));
        $this->assertSame('/api/transport-nearby', route('app.api.transport-nearby', absolute: false));
        $this->assertSame('/api/transit-route/42', route('app.api.transit-route', 42, absolute: false));
        $this->assertSame('/location/update', route('app.location.update', absolute: false));
        $this->assertSame('/map/tiles/1/2/3', route('app.map.tiles', ['z' => 1, 'x' => 2, 'y' => 3], absolute: false));
    }
}
