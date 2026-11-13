<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tighten\Ziggy\Ziggy;

class ZiggyRoutesTest extends TestCase
{
    /** @return list<string> */
    private function publishedRouteNames(): array
    {
        return array_keys((new Ziggy)->toArray()['routes']);
    }

    public function test_admin_and_internal_routes_are_not_published_to_the_browser(): void
    {
        $names = $this->publishedRouteNames();

        $this->assertContains('app.home', $names);
        $this->assertContains('app.api.transit.stops', $names);

        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(app\.admin\.|ghost\.|livewire\.|default-livewire\.|storage\.|sanctum\.|password\.)/',
                $name,
            );
        }
    }

    public function test_the_guest_login_page_does_not_reveal_admin_urls(): void
    {
        $this->withoutVite();

        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('"app.home"', false);
        $response->assertDontSee('app.admin.', false);
        $response->assertDontSee('admin\/users', false);
        $response->assertDontSee('ghost', false);
    }

    public function test_every_allow_listed_route_name_exists(): void
    {
        $routes = Route::getRoutes();
        $unknown = [];

        foreach (config('ziggy.only') as $pattern) {
            $found = str_contains($pattern, '*')
                ? collect($routes->getRoutesByName())->keys()->contains(fn (string $name) => fnmatch($pattern, $name))
                : $routes->hasNamedRoute($pattern);

            if (! $found) {
                $unknown[] = $pattern;
            }
        }

        $this->assertSame([], $unknown);
    }
}
