<?php

namespace Tests\Feature\Transit;

use App\Transit\Models\TransitFeed;
use App\Transit\Providers\GtfsStaticProvider;
use App\Transit\Services\FeedProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class GtfsStaticImportTest extends TestCase
{
    use RefreshDatabase;

    private function zipBody(string $routeName = '1'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gz');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('agency.txt', "agency_id,agency_name,agency_url,agency_timezone\nA,Test,https://x.test,Europe/Bucharest\n");
        $zip->addFromString('stops.txt', "\xEF\xBB\xBFstop_id,stop_name,stop_lat,stop_lon\nS1,Gara,45.15,26.82\nS2,Centru,45.16,26.83\nBAD,Fara coord,,\n");
        $zip->addFromString('routes.txt', "route_id,agency_id,route_short_name,route_long_name,route_type,route_color\nR1,A,{$routeName},Linia,3,#ff0000\n");
        $zip->addFromString('trips.txt', "route_id,service_id,trip_id,trip_headsign,direction_id,shape_id\nR1,WK,T1,Centru,0,SH1\n");
        $zip->addFromString('stop_times.txt', "trip_id,arrival_time,departure_time,stop_id,stop_sequence\nT1,25:10:00,25:10:30,S1,1\nT1,25:20:00,25:20:00,S2,2\n");
        $zip->addFromString('calendar.txt', "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nWK,1,1,1,1,1,0,0,20260101,20261231\n");
        $zip->addFromString('shapes.txt', "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\nSH1,45.16,26.83,2\nSH1,45.15,26.82,1\n");
        $zip->close();
        $body = file_get_contents($path);
        unlink($path);

        return $body;
    }

    private ?string $served = null;

    private function serve(string $body): void
    {
        $this->served = $body;
        if (! $this->fakeRegistered) {
            $this->fakeRegistered = true;
            Http::fake(['feeds.test/*' => fn () => Http::response($this->served)]);
        }
    }

    private bool $fakeRegistered = false;

    private function feed(): TransitFeed
    {
        return TransitFeed::create(['slug' => 'test', 'name' => 'Test', 'static_url' => 'https://feeds.test/gtfs.zip']);
    }

    public function test_imports_a_gtfs_archive_into_the_unified_tables(): void
    {
        $this->serve($this->zipBody());

        $feed = $this->feed();

        $this->assertTrue(app(GtfsStaticProvider::class)->importStatic($feed));

        $this->assertSame(2, DB::table('transit_stops')->count());
        $this->assertSame('FF0000', DB::table('transit_routes')->value('color'));
        $this->assertSame(25 * 3600 + 600, (int) DB::table('transit_stop_times')->orderBy('stop_sequence')->value('arrival_seconds'));
        $this->assertSame(31, (int) DB::table('transit_calendars')->value('days_mask'));
        $this->assertSame([[26.82, 45.15], [26.83, 45.16]], json_decode(DB::table('transit_shapes')->value('points'), true));
        $this->assertSame('imported', $feed->fresh()->import_status);
        $progress = app(FeedProgress::class)->get($feed);
        $this->assertSame('done', $progress['stage']);
        $this->assertSame(100, $progress['percent']);
    }

    public function test_unchanged_archives_are_skipped_and_changed_ones_replace_data(): void
    {
        $provider = app(GtfsStaticProvider::class);
        $feed = $this->feed();

        $this->serve($this->zipBody());

        $this->assertTrue($provider->importStatic($feed));
        $this->assertFalse($provider->importStatic($feed->fresh()));

        $this->serve($this->zipBody('7'));

        $this->assertTrue($provider->importStatic($feed->fresh()));
        $this->assertSame(1, DB::table('transit_routes')->count());
        $this->assertSame('7', DB::table('transit_routes')->value('short_name'));
    }

    public function test_invalid_archives_mark_the_feed_as_failed_without_touching_data(): void
    {
        $provider = app(GtfsStaticProvider::class);
        $feed = $this->feed();
        $this->serve($this->zipBody());
        $provider->importStatic($feed);

        $this->serve('not a zip');
        try {
            $provider->importStatic($feed->fresh());
            $this->fail('Expected failure.');
        } catch (\RuntimeException) {
        }

        $this->assertSame('failed', $feed->fresh()->import_status);
        $this->assertSame(2, DB::table('transit_stops')->count());
    }
}
