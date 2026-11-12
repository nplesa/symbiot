<?php

namespace Tests\Feature;

use App\Services\RailTimetableProvider;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RailTimetableTimezoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.rail_timetable.packages' => [['package' => 'tfc', 'operator' => 'Test Călători']]]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'package_show')) {
                return Http::response(['success' => true, 'result' => ['resources' => [[
                    'id' => 'new-timetable', 'url' => 'https://data.gov.ro/new.xml', 'created' => '2026-12-01T00:00:00Z',
                ]]]]);
            }

            // A timetable that starts on 14 December (a Romanian calendar day).
            return Http::response(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <XmlIf>
                    <XmlMts><Mt MtValabilDeLa="20261214" MtValabilPinaLa="20271212" /></XmlMts>
                    <Trenuri>
                        <Tren Numar="10241" CategorieTren="R">
                            <Trase><Trasa>
                                <ElementTrasa DenStaOrigine="Ploiesti Est" DenStaDestinatie="Ploiesti Nord Hm." OraP="20340" TipOprire="C" />
                                <ElementTrasa DenStaOrigine="Ploiesti Nord Hm." DenStaDestinatie="Ploiesti Sud" OraP="20640" TipOprire="C" />
                            </Trasa></Trase>
                            <RestrictiiTren><CalendarTren DeLa="20261214" PinaLa="20271212" Tip="Da" Zile="127" /></RestrictiiTren>
                        </Tren>
                    </Trenuri>
                </XmlIf>
                XML);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function services(): array
    {
        $pois = app(RailTimetableProvider::class)->addScheduledServices([
            ['type' => 'train', 'name' => 'Ploiești Nord'],
        ]);

        return $pois[0]['train_services'];
    }

    public function test_a_timetable_is_active_from_midnight_romanian_time(): void
    {
        // 00:30 on 14 December in Bucharest is still 13 December in UTC.
        Carbon::setTestNow(Carbon::parse('2026-12-13 22:30:00', 'UTC'));

        $this->assertNotEmpty($this->services());
    }

    public function test_a_timetable_is_not_active_before_midnight_romanian_time(): void
    {
        // 23:30 on 13 December in Bucharest.
        Carbon::setTestNow(Carbon::parse('2026-12-13 21:30:00', 'UTC'));

        $this->assertSame([], $this->services());
    }
}
