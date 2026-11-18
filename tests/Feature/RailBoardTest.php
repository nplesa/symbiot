<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RailBoardTest extends TestCase
{
    use RefreshDatabase;

    private const BERLIN = ['lat' => 52.5251, 'lon' => 13.3694];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.transitous.enabled' => true,
            'services.transitous.base_url' => 'https://transitous.test/api',
            'services.rail_timetable.packages' => [
                ['package' => 'mers-tren-transferoviar-calatori-s-r-l', 'operator' => 'Transferoviar Călători'],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function stopTime(array $overrides = []): array
    {
        return array_replace_recursive([
            'place' => [
                'name' => 'Berlin Hbf', 'stopId' => 'platform-8', 'tz' => 'Europe/Berlin', 'track' => '8',
                'departure' => '2026-11-16T13:43:00Z', 'scheduledDeparture' => '2026-11-16T13:38:00Z',
                'arrival' => '2026-11-16T13:31:00Z', 'scheduledArrival' => '2026-11-16T13:29:00Z',
            ],
            'mode' => 'HIGHSPEED_RAIL', 'realTime' => true, 'cancelled' => false, 'tripCancelled' => false,
            'displayName' => 'ICE 856', 'tripShortName' => '856', 'headsign' => 'Köln Hbf',
            'tripFrom' => ['name' => 'Berlin Ostbahnhof'], 'tripTo' => ['name' => 'Köln Hbf'],
            'agencyName' => 'DB Fernverkehr AG', 'agencyUrl' => 'https://www.bahn.de',
        ], $overrides);
    }

    private function fakeTransitous(array $stopTimes, ?array $reverse = null): void
    {
        Http::fake([
            'transitous.test/api/v1/reverse-geocode*' => Http::response($reverse ?? [
                // The nearest match is a bus stop: it must be skipped for the railway station.
                ['id' => 'bus-1', 'name' => 'Washingtonplatz', 'lat' => 52.5250, 'lon' => 13.3695, 'modes' => ['BUS']],
                ['id' => 'berlin-hbf', 'name' => 'Berlin Hauptbahnhof', 'lat' => 52.5249, 'lon' => 13.3692, 'tz' => 'Europe/Berlin', 'modes' => ['HIGHSPEED_RAIL', 'SUBURBAN', 'BUS']],
            ]),
            'transitous.test/api/v5/stoptimes*' => Http::response(['stopTimes' => $stopTimes]),
        ]);
    }

    private function fakeAnnualTimetable(): void
    {
        Http::fake([
            'data.gov.ro/api/3/action/package_show*' => Http::response(['success' => true, 'result' => [
                'resources' => [['id' => 'current', 'url' => 'https://data.gov.ro/timetable.xml', 'format' => 'XML', 'created' => '2025-12-01']],
            ]]),
            'data.gov.ro/timetable.xml' => Http::response(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <XmlIf>
                    <XmlMts><Mt MtValabilDeLa="20250101" MtValabilPinaLa="20991231" /></XmlMts>
                    <Trenuri>
                        <Tren Numar="10241" CategorieTren="R">
                            <Trase><Trasa>
                                <ElementTrasa DenStaOrigine="Ploiesti Nord" DenStaDestinatie="Ploiesti Sud" OraP="86340" TipOprire="C" />
                            </Trasa></Trase>
                            <RestrictiiTren><CalendarTren DeLa="20250101" PinaLa="20991231" Tip="Da" Zile="127" /></RestrictiiTren>
                        </Tren>
                    </Trenuri>
                </XmlIf>
                XML),
        ]);
    }

    private function board(array $query): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::factory()->create())
            ->getJson('/api/transit/rail-board?' . http_build_query($query));
    }

    public function test_departures_come_live_from_the_nearest_railway_station(): void
    {
        $this->fakeTransitous([
            $this->stopTime(),
            $this->stopTime(['displayName' => 'RE3', 'tripShortName' => '3309', 'headsign' => 'Wittenberg', 'realTime' => false, 'cancelled' => true, 'agencyUrl' => 'javascript:alert(1)']),
        ]);

        $this->board(self::BERLIN + ['name' => 'Berlin Hbf'])
            ->assertOk()
            ->assertJsonPath('type', 'departures')
            ->assertJsonPath('live.source', 'Transitous')
            ->assertJsonPath('live.attribution_url', 'https://transitous.org/sources/')
            ->assertJsonPath('live.station.name', 'Berlin Hauptbahnhof')
            ->assertJsonPath('live.station.timezone', 'Europe/Berlin')
            ->assertJsonPath('live.items.0.train', 'ICE 856')
            ->assertJsonPath('live.items.0.place', 'Köln Hbf')
            ->assertJsonPath('live.items.0.time', '2026-11-16T13:43:00Z')
            ->assertJsonPath('live.items.0.scheduled_time', '2026-11-16T13:38:00Z')
            ->assertJsonPath('live.items.0.delay_minutes', 5)
            ->assertJsonPath('live.items.0.track', '8')
            ->assertJsonPath('live.items.0.operator', 'DB Fernverkehr AG')
            ->assertJsonPath('live.items.0.operator_url', 'https://www.bahn.de')
            ->assertJsonPath('live.items.1.operator_url', null)
            ->assertJsonPath('live.items.1.delay_minutes', null)
            ->assertJsonPath('live.items.1.cancelled', true)
            ->assertJsonPath('live_failed', false)
            ->assertJsonPath('timetable', null);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/v5/stoptimes')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['stopId'] === 'berlin-hbf'
                && $query['arriveBy'] === 'false'
                && ! str_contains($query['mode'], 'SUBURBAN') && ! str_contains($query['mode'], 'BUS')
                && str_contains($request->header('User-Agent')[0] ?? '', 'Symbiot');
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'data.gov.ro'));
    }

    public function test_arrivals_are_the_next_ones_and_show_where_the_train_comes_from(): void
    {
        $this->fakeTransitous([$this->stopTime()]);

        $this->board(self::BERLIN + ['type' => 'arrivals'])
            ->assertOk()
            ->assertJsonPath('type', 'arrivals')
            ->assertJsonPath('live.items.0.place', 'Berlin Ostbahnhof')
            ->assertJsonPath('live.items.0.time', '2026-11-16T13:31:00Z')
            ->assertJsonPath('live.items.0.delay_minutes', 2);

        // arriveBy=true alone would search backwards in time.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v5/stoptimes')
            && str_contains($request->url(), 'arriveBy=true') && str_contains($request->url(), 'direction=LATER'));
    }

    public function test_without_a_railway_station_nearby_the_annual_timetable_is_the_fallback(): void
    {
        $this->fakeAnnualTimetable();
        Http::fake([
            // Only a far-away station (≈ 5 km) and a nearby bus stop.
            'transitous.test/api/v1/reverse-geocode*' => Http::response([
                ['id' => 'bus', 'name' => 'Bus', 'lat' => 44.9537, 'lon' => 26.0272, 'modes' => ['BUS']],
                ['id' => 'far', 'name' => 'Far', 'lat' => 45.0, 'lon' => 26.03, 'modes' => ['REGIONAL_RAIL']],
            ]),
        ]);

        $this->board(['lat' => 44.95368, 'lon' => 26.027174, 'name' => 'Ploiești Nord'])
            ->assertOk()
            ->assertJsonPath('live', null)
            ->assertJsonPath('live_failed', false)
            ->assertJsonPath('timetable.source', 'data.gov.ro')
            ->assertJsonPath('timetable.available', true)
            ->assertJsonStructure(['timetable' => ['services', 'valid_until']]);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/v5/stoptimes'));
    }

    public function test_a_transitous_outage_falls_back_to_the_annual_timetable(): void
    {
        $this->fakeAnnualTimetable();
        Http::fake(['transitous.test/*' => Http::response('Bad gateway', 502)]);

        $this->board(['lat' => 44.95368, 'lon' => 26.027174, 'name' => 'Ploiești Nord'])
            ->assertOk()
            ->assertJsonPath('live', null)
            ->assertJsonPath('live_failed', true)
            ->assertJsonPath('timetable.source', 'data.gov.ro');
    }

    public function test_transitous_can_be_switched_off(): void
    {
        config(['services.transitous.enabled' => false]);
        $this->fakeAnnualTimetable();

        $this->board(['lat' => 44.95368, 'lon' => 26.027174, 'name' => 'Ploiești Nord'])
            ->assertOk()
            ->assertJsonPath('live', null)
            ->assertJsonPath('timetable.source', 'data.gov.ro');

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'transitous'));
    }

    public function test_the_station_lookup_and_board_are_cached(): void
    {
        $this->fakeTransitous([$this->stopTime()]);

        $this->board(self::BERLIN)->assertOk();
        $this->board(self::BERLIN)->assertOk();

        Http::assertSentCount(2); // one reverse geocode + one stop times call, then both from cache
    }

    public function test_the_board_requires_a_logged_in_user_and_valid_input(): void
    {
        $this->getJson('/api/transit/rail-board?lat=52.5&lon=13.4')->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transit/rail-board?lat=99&lon=13.4&type=sideways')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'type']);
    }
}
