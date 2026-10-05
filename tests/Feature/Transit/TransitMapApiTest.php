<?php

namespace Tests\Feature\Transit;

use App\Models\User;
use App\Transit\Models\TransitFeed;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransitMapApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedFeed(): TransitFeed
    {
        $feed = TransitFeed::create([
            'slug' => 'map', 'name' => 'Map', 'static_url' => 'https://feeds.test/x.zip', 'static_hash' => 'h',
            'import_status' => 'imported', 'min_lat' => 44.3, 'max_lat' => 44.6, 'min_lon' => 25.9, 'max_lon' => 26.3,
        ]);
        $id = $feed->id;
        DB::table('transit_stops')->insert([
            ['feed_id' => $id, 'stop_id' => 'A/1', 'name' => 'Aproape', 'lat' => 44.4268, 'lon' => 26.1025, 'location_type' => 0],
            ['feed_id' => $id, 'stop_id' => 'B', 'name' => 'Departe', 'lat' => 44.55, 'lon' => 26.25, 'location_type' => 0],
        ]);
        DB::table('transit_routes')->insert(['feed_id' => $id, 'route_id' => 'M1', 'short_name' => 'M1', 'route_type' => 1, 'color' => 'FFD400']);
        DB::table('transit_trips')->insert([
            ['feed_id' => $id, 'trip_id' => 't1', 'route_id' => 'M1', 'service_id' => 's', 'direction_id' => 0, 'shape_id' => 'sh0'],
            ['feed_id' => $id, 'trip_id' => 't2', 'route_id' => 'M1', 'service_id' => 's', 'direction_id' => 0, 'shape_id' => 'sh0'],
            ['feed_id' => $id, 'trip_id' => 't3', 'route_id' => 'M1', 'service_id' => 's', 'direction_id' => 1, 'shape_id' => 'sh1'],
        ]);
        DB::table('transit_stop_times')->insert([
            ['feed_id' => $id, 'trip_id' => 't1', 'stop_id' => 'A/1', 'stop_sequence' => 1],
            ['feed_id' => $id, 'trip_id' => 't2', 'stop_id' => 'A/1', 'stop_sequence' => 1],
        ]);
        DB::table('transit_shapes')->insert([
            ['feed_id' => $id, 'shape_id' => 'sh0', 'points' => json_encode([[26.1, 44.4], [26.2, 44.5]])],
            ['feed_id' => $id, 'shape_id' => 'sh1', 'points' => json_encode([[26.2, 44.5], [26.1, 44.4]])],
        ]);

        return $feed;
    }

    public function test_stops_within_the_radius_are_returned_from_imported_feeds_only(): void
    {
        $feed = $this->seedFeed();
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/transit/stops?lat=44.4268&lon=26.1025&radius=1000')
            ->assertOk()
            ->assertJsonCount(1, 'stops')
            ->assertJsonPath('stops.0.name', 'Aproape')
            ->assertJsonPath('stops.0.feed_id', $feed->id);

        $feed->forceFill(['static_hash' => null])->save();
        $this->getJson('/api/transit/stops?lat=44.4268&lon=26.1025')->assertJsonCount(0, 'stops');
    }

    public function test_stops_in_the_corner_of_the_search_square_are_outside_the_circle(): void
    {
        $feed = $this->seedFeed();
        // ~900 m north and ~900 m east of the centre: inside a 1000 m square, ~1270 m away.
        DB::table('transit_stops')->insert([
            'feed_id' => $feed->id, 'stop_id' => 'corner', 'name' => 'Colt', 'lat' => 44.4268 + 0.0081, 'lon' => 26.1025 + 0.0113, 'location_type' => 0,
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transit/stops?lat=44.4268&lon=26.1025&radius=1000')
            ->assertJsonMissing(['stop_id' => 'corner']);
    }

    public function test_stop_routes_support_stop_ids_containing_slashes(): void
    {
        $feed = $this->seedFeed();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/transit/stops/{$feed->id}/A%2F1/routes")
            ->assertOk()
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.short_name', 'M1')
            ->assertJsonPath('routes.0.route_type', 1);
    }

    public function test_station_routes_include_the_lines_of_its_platforms(): void
    {
        $feed = $this->seedFeed();
        DB::table('transit_stops')->insert([
            ['feed_id' => $feed->id, 'stop_id' => 'ST', 'name' => 'Statie', 'lat' => 44.43, 'lon' => 26.1, 'location_type' => 1],
        ]);
        DB::table('transit_stops')->where('stop_id', 'A/1')->update(['parent_station' => 'ST']);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/transit/stops/{$feed->id}/ST/routes")
            ->assertJsonPath('routes.0.short_name', 'M1');
    }

    public function test_next_departure_uses_the_active_gtfs_service_and_stop_arrival_time(): void
    {
        $feed = $this->seedFeed();
        Carbon::setTestNow('2026-10-05 10:00:00');
        DB::table('transit_agencies')->insert([
            'feed_id' => $feed->id, 'agency_id' => 'agency', 'name' => 'Agency', 'timezone' => 'UTC',
        ]);
        DB::table('transit_calendars')->insert([
            'feed_id' => $feed->id,
            'service_id' => 's',
            'days_mask' => 127,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
        ]);
        DB::table('transit_trips')->where('trip_id', 't1')->update(['headsign' => 'Centru']);
        DB::table('transit_stop_times')->where('trip_id', 't1')->update([
            'arrival_seconds' => 10 * 3600 + 15 * 60,
            'departure_seconds' => 10 * 3600 + 16 * 60,
        ]);
        DB::table('transit_stop_times')->where('trip_id', 't2')->update([
            'arrival_seconds' => 10 * 3600 + 30 * 60,
            'departure_seconds' => 10 * 3600 + 31 * 60,
        ]);

        try {
            $this->actingAs(User::factory()->create())
                ->getJson("/api/transit/stops/{$feed->id}/A%2F1/next-departure?route_id=M1")
                ->assertOk()
                ->assertJsonPath('departure.time', '10:15')
                ->assertJsonPath('departure.minutes_until', 15)
                ->assertJsonPath('departure.headsign', 'Centru')
                ->assertJsonPath('departure.timezone', 'UTC');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_route_shape_returns_one_line_per_direction(): void
    {
        $feed = $this->seedFeed();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/transit/routes/{$feed->id}/M1/shape")
            ->assertOk()
            ->assertJsonCount(2, 'shapes')
            ->assertJsonPath('shapes.0.0', [26.1, 44.4]);
    }

    public function test_map_endpoints_require_authentication_and_valid_input(): void
    {
        $this->getJson('/api/transit/stops?lat=44&lon=26')->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->getJson('/api/transit/stops?lat=44&lon=26&radius=999999')->assertStatus(422);
    }
}
