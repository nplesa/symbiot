<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FuelBestPriceApiTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $station, string $network, string $price): string
    {
        return '<GasProduct><Network><Id>' . $network . '</Id><Name>' . $network . '</Name></Network><Price>' . $price . '</Price><Stationid>' . $station . '</Stationid></GasProduct>';
    }

    private function station(string $id, string $network, float $lat, float $lon): string
    {
        return '<GasStation><Addr><Addrstring>Str ' . $id . '</Addrstring><Location><Lat>' . $lat . '</Lat><Lon>' . $lon . '</Lon></Location></Addr>'
            . '<Id>' . $id . '</Id><Name>S' . $id . '</Name><Network><Id>' . $network . '</Id><Name>' . $network . '</Name></Network></GasStation>';
    }

    public function test_ranks_chains_by_average_price_and_returns_the_nearest_station(): void
    {
        Cache::flush();
        $xml = '<GasItems xmlns="http://schemas.datacontract.org/2004/07/pmonsvc.Models.Protos"><Products>'
            . $this->product('A1', 'PETROM', '10.00') . $this->product('A2', 'PETROM', '10.20')
            . $this->product('B1', 'OMV', '9.90') . $this->product('B2', 'OMV', '9.94')
            . $this->product('C1', 'MOL', '11.00')
            . '</Products><Stations>'
            . $this->station('A1', 'PETROM', 44.40, 26.10) . $this->station('A2', 'PETROM', 44.431, 26.101)
            . $this->station('B1', 'OMV', 44.41, 26.10) . $this->station('B2', 'OMV', 44.435, 26.10)
            . $this->station('C1', 'MOL', 44.43, 26.10)
            . '</Stations></GasItems>';
        Http::fake(['*' => Http::response($xml)]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/fuel/best?lat=44.43&lon=26.10&radius=3000&fuel=11')
            ->assertOk()
            ->assertJsonPath('chains.0.name', 'OMV')
            ->assertJsonPath('chains.0.average_price', 9.92)
            ->assertJsonPath('chains.0.nearest.name', 'SB2')
            ->assertJsonPath('chains.1.name', 'Petrom')
            ->assertJsonPath('chains.2.name', 'MOL');

        $this->assertSame(3, count($response->json('chains')));
    }

    public function test_ignores_chains_that_are_not_fuel_subcategories_and_honours_selected_brands(): void
    {
        Cache::flush();
        $xml = '<GasItems xmlns="http://schemas.datacontract.org/2004/07/pmonsvc.Models.Protos"><Products>'
            . $this->product('A1', 'PETROM', '10.00') . $this->product('B1', 'OMV', '9.90') . $this->product('X1', 'NECUNOSCUT', '5.00')
            . '</Products><Stations>'
            . $this->station('A1', 'PETROM', 44.40, 26.10) . $this->station('B1', 'OMV', 44.41, 26.10) . $this->station('X1', 'NECUNOSCUT', 44.42, 26.10)
            . '</Stations></GasItems>';
        Http::fake(['*' => Http::response($xml)]);
        $user = User::factory()->create();

        $all = $this->actingAs($user)->getJson('/api/fuel/best?lat=44.43&lon=26.10&fuel=11')->assertOk()->json('chains');
        $this->assertSame(['OMV', 'Petrom'], array_column($all, 'name'));

        $selected = $this->actingAs($user)->getJson('/api/fuel/best?lat=44.43&lon=26.10&fuel=11&brands=petrom')->assertOk()->json('chains');
        $this->assertSame(['Petrom'], array_column($selected, 'name'));
    }

    public function test_country_scope_returns_the_cheapest_chain_with_its_cheapest_station(): void
    {
        Cache::flush();
        $xml = '<GasItems xmlns="http://schemas.datacontract.org/2004/07/pmonsvc.Models.Protos"><Products>'
            . $this->product('A1', 'PETROM', '10.50') . $this->product('B1', 'OMV', '10.00') . $this->product('B2', 'OMV', '9.80')
            . '</Products><Stations>'
            . $this->station('A1', 'PETROM', 44.40, 26.10) . $this->station('B1', 'OMV', 44.41, 26.10) . $this->station('B2', 'OMV', 45.0, 25.0)
            . '</Stations></GasItems>';
        Http::fake(['*' => Http::response($xml)]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/fuel/best?lat=44.43&lon=26.10&fuel=11&scope=country')
            ->assertOk()
            ->assertJsonPath('chains.0.name', 'OMV')
            ->assertJsonPath('chains.0.average_price', 9.9)
            ->assertJsonPath('chains.0.nearest.name', 'SB2')
            ->assertJsonPath('chains.0.nearest.price', 9.8);
    }

    public function test_country_scope_chooses_the_nearest_station_when_national_prices_tie(): void
    {
        Cache::flush();
        $xml = '<GasItems xmlns="http://schemas.datacontract.org/2004/07/pmonsvc.Models.Protos"><Products>'
            . $this->product('A1', 'PETROM', '10.05') . $this->product('A2', 'PETROM', '10.05')
            . '</Products><Stations>'
            . $this->station('A1', 'PETROM', 46.07, 23.58) . $this->station('A2', 'PETROM', 45.65, 25.60)
            . '</Stations></GasItems>';
        Http::fake(['*' => Http::response($xml)]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/fuel/best?lat=45.65&lon=25.60&fuel=11&scope=country')
            ->assertOk()
            ->assertJsonPath('chains.0.name', 'Petrom')
            ->assertJsonPath('chains.0.nearest.name', 'SA2')
            ->assertJsonPath('chains.0.nearest.price', 10.05)
            ->assertJsonPath('chains.0.nearest.meters', 0)
            ->assertJsonPath('chains.0.nearest.city', 'Brașov');
    }

    public function test_rejects_unknown_fuel(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/fuel/best?lat=44.43&lon=26.10&fuel=99')
            ->assertUnprocessable();
    }

    public function test_reports_unavailable_service(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('', 500)]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/fuel/best?lat=44.43&lon=26.10&fuel=11')
            ->assertStatus(502);
    }
}
