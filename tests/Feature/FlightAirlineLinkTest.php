<?php

namespace Tests\Feature;

use App\Services\FlightAwareProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlightAirlineLinkTest extends TestCase
{
    public function test_flights_carry_the_airline_name_and_website_from_the_iata_code(): void
    {
        config([
            'services.flightaware.key' => 'test-key',
            'services.flightaware.base_url' => 'https://aero.test/aeroapi',
            'airlines.XQ' => ['name' => 'Example Air', 'url' => 'https://example-air.test/'],
        ]);
        $departure = fn (array $flight): array => $flight + [
            'scheduled_out' => now()->addHour()->toIso8601String(),
            'origin' => ['code_iata' => 'OTP'], 'destination' => ['code_iata' => 'FCO'],
        ];
        Http::fake([
            'aero.test/aeroapi/airports/OTP/flights/scheduled_arrivals*' => Http::response(['scheduled_arrivals' => []]),
            'aero.test/aeroapi/airports/OTP/flights/scheduled_departures*' => Http::response(['scheduled_departures' => [
                $departure(['ident_iata' => 'XQ101', 'operator_iata' => 'XQ']),
                // No operator_iata: the code comes from the flight number.
                $departure(['ident_iata' => 'XQ202']),
                $departure(['ident_iata' => 'ZZ303', 'operator_iata' => 'ZZ']),
            ]]),
        ]);

        $flights = collect(app(FlightAwareProvider::class)->airportBoard('OTP')['departures'])->keyBy('number');

        $this->assertSame('Example Air', $flights['XQ101']['operator_name']);
        $this->assertSame('https://example-air.test/', $flights['XQ101']['operator_url']);
        $this->assertSame('https://example-air.test/', $flights['XQ202']['operator_url']);
        $this->assertNull($flights['ZZ303']['operator_name']);
        $this->assertNull($flights['ZZ303']['operator_url']);
    }

    public function test_the_airline_list_has_only_web_links(): void
    {
        $airlines = require base_path('config/airlines.php');

        $this->assertGreaterThan(500, count($airlines));
        $this->assertSame('TAROM', $airlines['RO']['name']);
        foreach ($airlines as $code => $airline) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{2}$/', (string) $code);
            $this->assertMatchesRegularExpression('#^https?://#i', $airline['url'], "Airline {$code}");
        }
    }
}
