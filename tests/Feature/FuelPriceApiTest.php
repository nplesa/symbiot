<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FuelPriceApiTest extends TestCase
{
    use RefreshDatabase;

    private function xml(string $stationId, float $lat, float $lon, string $category, string $price): string
    {
        return '<GasItems xmlns="http://schemas.datacontract.org/2004/07/pmonsvc.Models.Protos">'
            . '<Services><GasServiceCatalog><Id>1</Id><Name>Magazin</Name><Stationid>' . $stationId . '</Stationid></GasServiceCatalog></Services><Products><GasProduct><Name>Prod</Name><Price>' . $price . '</Price><Stationid>' . $stationId . '</Stationid></GasProduct></Products>'
            . '<Stations><GasStation><Addr><Addrstring>Str. Test 1</Addrstring><Location><Lat>' . $lat . '</Lat><Lon>' . $lon . '</Lon></Location></Addr>'
            . '<Id>' . $stationId . '</Id><Name>Test</Name><Network><Name>PETROM</Name></Network><Updatedate>05/10/2026 08:00</Updatedate></GasStation></Stations></GasItems>';
    }

    public function test_returns_prices_for_the_matching_station(): void
    {
        Cache::flush();
        Http::fake([
            '*CSVGasCatalogProductIds=21*' => Http::response($this->xml('R1', 44.4300, 26.1000, '21', '10.91')),
            '*CSVGasCatalogProductIds=11*' => Http::response($this->xml('R1', 44.4300, 26.1000, '11', '10.05')),
            '*' => Http::response('<GasItems xmlns="http://schemas.datacontract.org/2004/07/pmonsvc.Models.Protos"><Products/><Stations/></GasItems>'),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/fuel/prices?lat=44.4301&lon=26.1001&brand=Petrom')
            ->assertOk()
            ->assertJsonPath('station.id', 'R1')
            ->assertJsonPath('station.services', ['Magazin'])
            ->assertJsonPath('prices.0.label', 'Benzină standard')
            ->assertJsonPath('prices.0.price', 10.05)
            ->assertJsonPath('prices.1.label', 'Motorină standard');
    }

    public function test_station_of_another_brand_is_not_matched(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response($this->xml('R1', 44.4300, 26.1000, '21', '10.91'))]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/fuel/prices?lat=44.4300&lon=26.1000&brand=Lukoil')
            ->assertOk()
            ->assertJsonPath('station', null)
            ->assertJsonPath('prices', []);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/fuel/prices?lat=44&lon=26')->assertUnauthorized();
    }
}
