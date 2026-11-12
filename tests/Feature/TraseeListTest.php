<?php

namespace Tests\Feature;

use App\Livewire\Trasee\Index;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class TraseeListTest extends TestCase
{
    use RefreshDatabase;

    private function route(User $user, string $name, float $marker): Route
    {
        return Route::create([
            'user_id' => $user->id,
            'name' => $name,
            'distance' => 12345,
            // A recognisable coordinate, to find this geometry in the rendered HTML.
            'geometry' => ['type' => 'LineString', 'coordinates' => [[$marker, 45.1], [$marker, 45.2]]],
        ]);
    }

    public function test_route_list_does_not_embed_the_geometry_of_every_route(): void
    {
        $user = User::factory()->create();
        $first = $this->route($user, 'Primul traseu', 21.123456);
        $this->route($user, 'Al doilea traseu', 22.654321);

        $component = Livewire::actingAs($user)->test(Index::class)
            ->assertSee('Primul traseu')
            ->assertSee('Al doilea traseu')
            ->assertSee('12,35 km')
            ->assertDontSee('22.654321')
            ->assertDontSee('21.123456');

        // Only the selected route's geometry is rendered, for the map preview.
        $component->call('selectRoute', $first->id)
            ->assertSee('21.123456')
            ->assertDontSee('22.654321');
    }

    public function test_the_list_query_does_not_load_geometries(): void
    {
        $user = User::factory()->create();
        $this->route($user, 'Traseu', 21.5);

        DB::enableQueryLog();
        Livewire::actingAs($user)->test(Index::class);
        $listQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'from "routes"') && str_contains($sql, 'order by'));

        $this->assertNotEmpty($listQueries);
        $listQueries->each(fn (string $sql) => $this->assertStringNotContainsString('geometry', $sql));
        $listQueries->each(fn (string $sql) => $this->assertStringNotContainsString('"routes".*', $sql));
    }
}
