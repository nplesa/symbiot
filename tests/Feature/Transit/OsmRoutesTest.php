<?php

namespace Tests\Feature\Transit;

use App\Models\User;
use App\Transit\Models\TransitFeed;
use App\Transit\Osm\OplReader;
use App\Transit\Osm\OsmCountySync;
use App\Transit\Osm\OsmRouteImporter;
use App\Transit\Services\FeedDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OsmRoutesTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'opl');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function opl(): string
    {
        return implode("\n", [
            'n1 v1 dV c0 t i0 u Tname=Gara x25.00 y45.00',
            'n2 v1 dV c0 t i0 u Tname=Centru x25.01 y45.01',
            'n3 v1 dV c0 t i0 u Tname=Spital%20%Nou x25.02 y45.02',
            'n4 v1 dV c0 t i0 u T x25.03 y45.03',
            'n5 v1 dV c0 t i0 u T x25.05 y45.05',
            'n6 v1 dV c0 t i0 u T x25.06 y45.06',
            'w10 v1 dV c0 t i0 u Thighway=residential N1,n2,n3',
            'w11 v1 dV c0 t i0 u Thighway=residential N4,n3',
            'w12 v1 dV c0 t i0 u Thighway=residential N5,n6',
            'r100 v1 dV c0 t i0 u Tcolour=#BE1622,name=Bus%20%7:%20%Gara%20%=>%20%Spital,network=Urban%20%Trans,ref=7,route=bus,to=Spital%20%Nou,type=route Mn1@stop,n2@stop,n3@stop,w10@,w11@,w12@',
            'r101 v1 dV c0 t i0 u Tname=Bus%20%7:%20%Spital%20%=>%20%Gara,network=Urban%20%Trans,ref=7,route=bus,type=route Mn3@stop,n2@stop,n1@stop,w10@',
            'r102 v1 dV c0 t i0 u Tname=Fantoma,ref=9,route=bus,type=route Mn900@stop,n901@stop,n1@stop',
            'r103 v1 dV c0 t i0 u Tname=Ruta,route=railway,type=route Mn1@stop',
            '',
        ]);
    }

    private function feed(array $attributes = []): TransitFeed
    {
        return TransitFeed::create($attributes + [
            'slug' => 'osm-test', 'name' => 'OSM test', 'provider' => 'osm',
            'min_lat' => 44.9, 'max_lat' => 45.2, 'min_lon' => 24.9, 'max_lon' => 25.2,
        ]);
    }

    public function test_reader_decodes_escaped_values_and_members(): void
    {
        $route = OplReader::parse(explode("\n", $this->opl())[9]);

        $this->assertSame('Bus 7: Gara => Spital', $route['tags']['name']);
        $this->assertSame('Urban Trans', $route['tags']['network']);
        $this->assertSame(['n', 1, 'stop'], $route['members'][0]);
        $this->assertSame(['w', 12, ''], $route['members'][5]);
        $this->assertSame([1, 2, 3], OplReader::parse(explode("\n", $this->opl())[6])['nodes']);
    }

    public function test_importer_groups_directions_into_one_route_with_ordered_stops_and_shapes(): void
    {
        file_put_contents($this->file, $this->opl());
        $feed = $this->feed();

        $result = (new OsmRouteImporter)->import($feed, $this->file);

        $this->assertSame(1, $result['routes']);
        $this->assertSame(2, $result['trips']);
        $this->assertSame(3, $result['stops']);

        $route = DB::table('transit_routes')->where('feed_id', $feed->id)->first();
        $this->assertSame('7', $route->short_name);
        $this->assertSame(3, (int) $route->route_type);
        $this->assertSame('BE1622', $route->color);

        $this->assertSame(
            ['n1', 'n2', 'n3'],
            DB::table('transit_stop_times')->where('feed_id', $feed->id)->where('trip_id', 'r100')->orderBy('stop_sequence')->pluck('stop_id')->all()
        );
        $this->assertSame('Spital Nou', DB::table('transit_stops')->where('feed_id', $feed->id)->where('stop_id', 'n3')->value('name'));

        $directions = DB::table('transit_trips')->where('feed_id', $feed->id)->orderBy('trip_id')->pluck('direction_id')->all();
        $this->assertSame([0, 1], $directions);
        $this->assertSame('Spital Nou', DB::table('transit_trips')->where('trip_id', 'r100')->value('headsign'));

        // w10 and w11 join into one line, w12 stays a separate piece.
        $main = json_decode(DB::table('transit_shapes')->where('feed_id', $feed->id)->where('shape_id', 'r100')->value('points'), true);
        $this->assertCount(4, $main);
        $this->assertSame(1, DB::table('transit_shapes')->where('feed_id', $feed->id)->where('shape_id', 'r100~1')->count());
    }

    public function test_importer_skips_routes_that_belong_to_a_neighbouring_area_and_is_repeatable(): void
    {
        file_put_contents($this->file, $this->opl());
        $feed = $this->feed();
        $importer = new OsmRouteImporter;

        $importer->import($feed, $this->file);
        $importer->import($feed, $this->file);

        $this->assertSame(0, DB::table('transit_trips')->where('trip_id', 'r102')->count());
        $this->assertSame(2, DB::table('transit_trips')->where('feed_id', $feed->id)->count());
        $this->assertSame(1, DB::table('transit_calendars')->where('feed_id', $feed->id)->count());
    }

    public function test_county_sync_creates_feeds_with_bbox_and_keeps_them_across_runs(): void
    {
        file_put_contents($this->file, implode("\n", [
            'n1 v1 dV c0 t i0 u T x25.1 y44.4',
            'n2 v1 dV c0 t i0 u T x26.0 y45.4',
            'w1 v1 dV c0 t i0 u T N1,n2',
            'r1 v1 dV c0 t i0 u TISO3166-2=RO-DB,admin_level=4,boundary=administrative,name=Dambovita Mw1@outer',
            'r2 v1 dV c0 t i0 u TISO3166-2=MD-FA,admin_level=4,boundary=administrative,name=Raion Mw1@outer',
            '',
        ]));
        $sync = new OsmCountySync;

        $this->assertSame(1, $sync->syncFromOpl($this->file));
        $this->assertSame(1, $sync->syncFromOpl($this->file));

        $feed = TransitFeed::where('slug', 'osm-ro-db')->sole();
        $this->assertSame('osm', $feed->provider);
        $this->assertSame('pending', $feed->import_status);
        $this->assertEqualsWithDelta(44.4, (float) $feed->min_lat, 0.001);
        $this->assertEqualsWithDelta(26.0, (float) $feed->max_lon, 0.001);
    }

    public function test_county_polygon_keeps_a_border_route_in_one_county_only(): void
    {
        // Two counties split at lon 25.03: west owns stops n1-n3, east owns the route stops n7-n9.
        file_put_contents($this->file, implode("\n", [
            'n1 v1 dV c0 t i0 u T x24.90 y44.90',
            'n2 v1 dV c0 t i0 u T x25.03 y44.90',
            'n3 v1 dV c0 t i0 u T x25.03 y45.20',
            'n4 v1 dV c0 t i0 u T x24.90 y45.20',
            'n5 v1 dV c0 t i0 u T x25.20 y44.90',
            'n6 v1 dV c0 t i0 u T x25.20 y45.20',
            'w1 v1 dV c0 t i0 u T N1,n2,n3',
            'w2 v1 dV c0 t i0 u T N3,n4,n1',
            'w3 v1 dV c0 t i0 u T N2,n5,n6',
            'w4 v1 dV c0 t i0 u T N6,n3',
            'r1 v1 dV c0 t i0 u TISO3166-2=RO-WW,admin_level=4,boundary=administrative,name=West Mw1@outer,w2@outer',
            'r2 v1 dV c0 t i0 u TISO3166-2=RO-EE,admin_level=4,boundary=administrative,name=East Mw3@outer,w4@outer',
            '',
        ]));
        (new OsmCountySync)->syncFromOpl($this->file);
        $west = TransitFeed::where('slug', 'osm-ro-ww')->sole();
        $east = TransitFeed::where('slug', 'osm-ro-ee')->sole();
        $this->assertNotEmpty($west->boundary);

        // Both bboxes cover the route, but its stops are in the east.
        $west->update(['max_lon' => 25.2]);
        file_put_contents($this->file, implode("\n", [
            'n7 v1 dV c0 t i0 u Tname=A x25.10 y45.00',
            'n8 v1 dV c0 t i0 u Tname=B x25.11 y45.01',
            'n9 v1 dV c0 t i0 u Tname=C x24.95 y45.02',
            'r5 v1 dV c0 t i0 u Tname=L,ref=1,route=bus,type=route Mn7@stop,n8@stop,n9@stop',
            '',
        ]));
        $importer = new OsmRouteImporter;
        $importer->import($west->fresh(), $this->file);
        $importer->import($east->fresh(), $this->file);

        $this->assertSame(0, DB::table('transit_trips')->where('feed_id', $west->id)->count());
        $this->assertSame(1, DB::table('transit_trips')->where('feed_id', $east->id)->count());
    }

    public function test_osm_feeds_only_apply_where_no_regional_gtfs_feed_exists(): void
    {
        $county = $this->feed(['min_lat' => 44.4, 'max_lat' => 45.4, 'min_lon' => 25.1, 'max_lon' => 26.0]);
        $city = TransitFeed::create([
            'slug' => 'gtfs-city', 'name' => 'City', 'provider' => 'gtfs',
            'min_lat' => 44.90, 'max_lat' => 44.95, 'min_lon' => 25.43, 'max_lon' => 25.48,
        ]);
        $national = TransitFeed::create([
            'slug' => 'gtfs-national', 'name' => 'National', 'provider' => 'gtfs',
            'min_lat' => 43.6, 'max_lat' => 48.3, 'min_lon' => 20.2, 'max_lon' => 29.7,
        ]);
        $discovery = new FeedDiscovery;

        $this->assertEqualsCanonicalizing(
            [$county->slug, $national->slug],
            $discovery->coverageFor(44.98, 25.64)->pluck('slug')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$city->slug, $national->slug],
            $discovery->coverageFor(44.925, 25.455)->pluck('slug')->all()
        );
    }

    public function test_route_shape_includes_disconnected_osm_pieces(): void
    {
        $feed = $this->feed();
        DB::table('transit_trips')->insert(['feed_id' => $feed->id, 'trip_id' => 'r1', 'route_id' => 'x', 'service_id' => 'osm', 'direction_id' => 0, 'shape_id' => 'r1']);
        DB::table('transit_shapes')->insert([
            ['feed_id' => $feed->id, 'shape_id' => 'r1', 'points' => json_encode([[25.0, 45.0], [25.1, 45.1]])],
            ['feed_id' => $feed->id, 'shape_id' => 'r1~1', 'points' => json_encode([[25.2, 45.2], [25.3, 45.3]])],
            ['feed_id' => $feed->id, 'shape_id' => 'r12', 'points' => json_encode([[1, 1], [2, 2]])],
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/transit/routes/{$feed->id}/x/shape")
            ->assertOk()
            ->assertJsonCount(2, 'shapes');
    }
}
