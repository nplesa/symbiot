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

    public function test_stop_routes_link_to_the_ticket_pages_of_their_operators(): void
    {
        $feed = $this->seedFeed();
        DB::table('transit_agencies')->insert([
            ['feed_id' => $feed->id, 'agency_id' => 'STB', 'name' => 'STB', 'url' => 'https://stb.test', 'fare_url' => 'https://stb.test/bilete'],
            ['feed_id' => $feed->id, 'agency_id' => 'MET', 'name' => 'Metrorex', 'url' => 'https://metrorex.test', 'fare_url' => null],
            ['feed_id' => $feed->id, 'agency_id' => 'BAD', 'name' => 'Bad', 'url' => 'javascript:alert(1)', 'fare_url' => null],
        ]);
        DB::table('transit_routes')->where('route_id', 'M1')->update(['agency_id' => 'MET']);
        DB::table('transit_fare_media')->insert([
            ['feed_id' => $feed->id, 'fare_media_id' => 'card', 'name' => 'Card bancar', 'type' => 3],
            ['feed_id' => $feed->id, 'fare_media_id' => 'app', 'name' => '24pay', 'type' => 4],
            ['feed_id' => $feed->id, 'fare_media_id' => 'paper', 'name' => 'Bilet hârtie', 'type' => 1],
        ]);
        $this->actingAs(User::factory()->create());

        // Only the operator of the lines at this stop: its home page when the feed has no fare page.
        $this->getJson("/api/transit/stops/{$feed->id}/A%2F1/routes")
            ->assertOk()
            ->assertJsonPath('tickets.operators', [['agency_id' => 'MET', 'name' => 'Metrorex', 'url' => 'https://metrorex.test', 'fare_page' => false]])
            ->assertJsonPath('tickets.contactless', true)
            ->assertJsonPath('tickets.apps', ['24pay']);

        DB::table('transit_routes')->where('route_id', 'M1')->update(['agency_id' => 'STB']);
        $this->getJson("/api/transit/stops/{$feed->id}/A%2F1/routes")
            ->assertJsonPath('tickets.operators.0.url', 'https://stb.test/bilete')
            ->assertJsonPath('tickets.operators.0.fare_page', true);
    }

    public function test_stop_routes_without_ticket_data_have_empty_tickets(): void
    {
        $feed = $this->seedFeed();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/transit/stops/{$feed->id}/A%2F1/routes")
            ->assertJsonPath('tickets', ['operators' => [], 'contactless' => false, 'apps' => []]);
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
        // t1 runs on from "Aproape" (A/1) to "Departe" (B): the trip ends of the details card.
        DB::table('transit_stop_times')->insert(['feed_id' => $feed->id, 'trip_id' => 't1', 'stop_id' => 'B', 'stop_sequence' => 2, 'arrival_seconds' => 10 * 3600 + 40 * 60]);

        try {
            $this->actingAs(User::factory()->create())
                ->getJson("/api/transit/stops/{$feed->id}/A%2F1/next-departure?route_id=M1")
                ->assertOk()
                ->assertJsonPath('departure.time', '10:15')
                ->assertJsonPath('departure.minutes_until', 15)
                ->assertJsonPath('departure.headsign', 'Centru')
                ->assertJsonPath('departure.timezone', 'UTC')
                ->assertJsonPath('departure.origin', 'Aproape')
                ->assertJsonPath('departure.destination', 'Departe');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_next_departure_resolves_gtfs_frequency_based_metro_service(): void
    {
        $feed = $this->seedFeed();
        Carbon::setTestNow('2026-10-05 10:04:00');
        DB::table('transit_calendars')->insert([
            'feed_id' => $feed->id,
            'service_id' => 's',
            'days_mask' => 127,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
        ]);
        DB::table('transit_agencies')->insert([
            'feed_id' => $feed->id, 'agency_id' => 'agency', 'name' => 'Metro', 'timezone' => 'UTC',
        ]);
        DB::table('transit_stop_times')->where('feed_id', $feed->id)->where('trip_id', 't1')->update([
            'departure_seconds' => 36000,
        ]);
        DB::table('transit_frequencies')->insert([
            'feed_id' => $feed->id,
            'trip_id' => 't1',
            'start_seconds' => 36000,
            'end_seconds' => 39600,
            'headway_secs' => 600,
            'exact_times' => 1,
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/transit/stops/{$feed->id}/A%2F1/next-departure?route_id=M1")
            ->assertOk()
            ->assertJsonPath('departure.time', '10:10')
            ->assertJsonPath('departure.estimated', false);

        Carbon::setTestNow();
    }

    public function test_next_departure_of_an_agency_without_timezone_uses_the_display_timezone(): void
    {
        $feed = $this->seedFeed();
        // 10:00 in Bucharest (UTC+3 in summer time); a 10:15 departure is 15 minutes away, not 3 h 15 min.
        Carbon::setTestNow('2026-10-05 07:00:00');
        DB::table('transit_agencies')->insert([
            'feed_id' => $feed->id, 'agency_id' => 'agency', 'name' => 'Agency', 'timezone' => '',
        ]);
        DB::table('transit_calendars')->insert([
            'feed_id' => $feed->id,
            'service_id' => 's',
            'days_mask' => 127,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
        ]);
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
                ->assertJsonPath('departure.timezone', 'Europe/Bucharest');
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

    public function test_route_shape_lists_the_line_stops_of_its_longest_trip_per_direction(): void
    {
        $feed = $this->seedFeed();
        $id = $feed->id;
        DB::table('transit_stops')->insert([
            ['feed_id' => $id, 'stop_id' => 'C', 'name' => 'Centru', 'lat' => 44.45, 'lon' => 26.15, 'location_type' => 0],
            ['feed_id' => $id, 'stop_id' => 'X', 'name' => 'Alta linie', 'lat' => 44.46, 'lon' => 26.16, 'location_type' => 0],
        ]);
        // t1 (direction 0) runs A/1 → C → B, t2 only A/1; t3 (direction 1) runs B → C → A/1.
        DB::table('transit_stop_times')->insert([
            ['feed_id' => $id, 'trip_id' => 't1', 'stop_id' => 'C', 'stop_sequence' => 2],
            ['feed_id' => $id, 'trip_id' => 't1', 'stop_id' => 'B', 'stop_sequence' => 3],
            ['feed_id' => $id, 'trip_id' => 't3', 'stop_id' => 'B', 'stop_sequence' => 1],
            ['feed_id' => $id, 'trip_id' => 't3', 'stop_id' => 'C', 'stop_sequence' => 2],
            ['feed_id' => $id, 'trip_id' => 't3', 'stop_id' => 'A/1', 'stop_sequence' => 3],
        ]);

        $stops = $this->actingAs(User::factory()->create())
            ->getJson("/api/transit/routes/{$id}/M1/shape")
            ->assertOk()
            ->assertJsonPath('stops.0.feed_id', $id)
            ->json('stops');

        // Each stop once, in the order of the first direction; the stop of another line is not listed.
        $this->assertSame(['A/1', 'C', 'B'], array_column($stops, 'stop_id'));
        $this->assertSame('Centru', $stops[1]['name']);
    }

    public function test_map_endpoints_require_authentication_and_valid_input(): void
    {
        $this->getJson('/api/transit/stops?lat=44&lon=26')->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->getJson('/api/transit/stops?lat=44&lon=26&radius=999999')->assertStatus(422);
    }

    /** A feed whose stops lie north of 45.0/26.0, $count of them, the farthest inserted first. */
    private function seedStopLine(int $count): TransitFeed
    {
        $feed = TransitFeed::create([
            'slug' => 'line', 'name' => 'Line', 'provider' => 'gtfs', 'static_url' => 'https://feeds.test/line.zip',
            'static_hash' => 'h', 'import_status' => 'imported', 'last_imported_at' => now(), 'active' => true,
            'min_lat' => 44.9, 'max_lat' => 45.1, 'min_lon' => 25.9, 'max_lon' => 26.1,
        ]);
        $rows = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            // ~22 m apart, so all of them fit in the 500 m departure search radius.
            $rows[] = ['feed_id' => $feed->id, 'stop_id' => "S{$i}", 'name' => "Stop {$i}", 'lat' => 45.0 + $i * 0.0002, 'lon' => 26.0, 'location_type' => 0];
        }
        DB::table('transit_stops')->insert($rows);

        return $feed;
    }

    public function test_stops_are_capped_to_the_nearest_ones_and_flagged_as_truncated(): void
    {
        $this->seedStopLine(30);
        $this->actingAs(User::factory()->create());

        config(['transit.max_map_stops' => 10]);
        $response = $this->getJson('/api/transit/stops?lat=45.0&lon=26.0&radius=5000')
            ->assertOk()
            ->assertJsonCount(10, 'stops')
            ->assertJsonPath('truncated', true);
        $this->assertSame(
            array_map(fn (int $i): string => "Stop {$i}", range(0, 9)),
            array_column($response->json('stops'), 'name')
        );

        config(['transit.max_map_stops' => 30]);
        $this->getJson('/api/transit/stops?lat=45.0&lon=26.0&radius=5000')
            ->assertJsonCount(30, 'stops')
            ->assertJsonPath('truncated', false);
    }

    public function test_nearest_next_departure_uses_the_closest_stop_even_with_many_candidates(): void
    {
        $feed = $this->seedStopLine(15);
        Carbon::setTestNow('2026-10-05 10:00:00');
        DB::table('transit_agencies')->insert(['feed_id' => $feed->id, 'agency_id' => 'a', 'name' => 'A', 'timezone' => 'UTC']);
        DB::table('transit_calendars')->insert([
            'feed_id' => $feed->id, 'service_id' => 's', 'days_mask' => 127, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10',
        ]);
        DB::table('transit_routes')->insert(['feed_id' => $feed->id, 'route_id' => 'R5', 'short_name' => '5', 'route_type' => 3]);
        DB::table('transit_trips')->insert(['feed_id' => $feed->id, 'trip_id' => 't', 'route_id' => 'R5', 'service_id' => 's', 'direction_id' => 0]);
        $stopTimes = [];
        // Inserted farthest first: without ORDER BY before LIMIT the closest stop would be cut off.
        for ($i = 14; $i >= 0; $i--) {
            $stopTimes[] = [
                'feed_id' => $feed->id, 'trip_id' => 't', 'stop_id' => "S{$i}", 'stop_sequence' => $i + 1,
                'arrival_seconds' => 10 * 3600 + (20 + $i) * 60, 'departure_seconds' => 10 * 3600 + (20 + $i) * 60,
            ];
        }
        DB::table('transit_stop_times')->insert($stopTimes);
        $candidateQueries = [];
        DB::listen(function ($query) use (&$candidateQueries): void {
            if (str_contains($query->sql, 'LOWER(TRIM(r.short_name))')) {
                $candidateQueries[] = strtolower($query->sql);
            }
        });

        try {
            $this->actingAs(User::factory()->create())
                ->getJson('/api/transit/stops/nearest-next-departure?lat=45.0&lon=26.0&route_ref=5')
                ->assertOk()
                ->assertJsonPath('stop_name', 'Stop 0')
                ->assertJsonPath('distance_meters', 0)
                ->assertJsonPath('departure.time', '10:20');
        } finally {
            Carbon::setTestNow();
        }

        // The row order of DISTINCT + LIMIT is engine-dependent, so the query itself must
        // sort by distance before limiting the candidates.
        $this->assertCount(1, $candidateQueries);
        $this->assertMatchesRegularExpression('/order by .*s\.lat - \?.*limit 10/s', $candidateQueries[0]);
    }

    /** M1 at one station: towards Pantelimon at 10:05, towards Dristor 2 at 10:10 (headsign) and 10:20 (no headsign). */
    private function seedTwoDirections(): TransitFeed
    {
        $feed = $this->seedStopLine(1);
        DB::table('transit_stops')->insert([
            ['feed_id' => $feed->id, 'stop_id' => 'EAST', 'name' => 'Dristor 2', 'lat' => 45.01, 'lon' => 26.01, 'location_type' => 0],
            ['feed_id' => $feed->id, 'stop_id' => 'WEST', 'name' => 'Pantelimon', 'lat' => 44.99, 'lon' => 25.99, 'location_type' => 0],
        ]);
        DB::table('transit_agencies')->insert(['feed_id' => $feed->id, 'agency_id' => 'a', 'name' => 'Metrorex', 'timezone' => 'UTC']);
        DB::table('transit_calendars')->insert([
            'feed_id' => $feed->id, 'service_id' => 's', 'days_mask' => 127, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10',
        ]);
        DB::table('transit_routes')->insert(['feed_id' => $feed->id, 'route_id' => 'M1', 'short_name' => 'M1', 'route_type' => 1]);
        DB::table('transit_trips')->insert([
            ['feed_id' => $feed->id, 'trip_id' => 'west', 'route_id' => 'M1', 'service_id' => 's', 'direction_id' => 1, 'headsign' => 'Pantelimon'],
            ['feed_id' => $feed->id, 'trip_id' => 'east', 'route_id' => 'M1', 'service_id' => 's', 'direction_id' => 0, 'headsign' => 'Dristor 2'],
            ['feed_id' => $feed->id, 'trip_id' => 'east-late', 'route_id' => 'M1', 'service_id' => 's', 'direction_id' => 0, 'headsign' => null],
        ]);
        $at = fn (int $minutes): int => 10 * 3600 + $minutes * 60;
        DB::table('transit_stop_times')->insert([
            ['feed_id' => $feed->id, 'trip_id' => 'west', 'stop_id' => 'S0', 'stop_sequence' => 1, 'arrival_seconds' => $at(5), 'departure_seconds' => $at(5)],
            ['feed_id' => $feed->id, 'trip_id' => 'west', 'stop_id' => 'WEST', 'stop_sequence' => 2, 'arrival_seconds' => $at(15), 'departure_seconds' => $at(15)],
            ['feed_id' => $feed->id, 'trip_id' => 'east', 'stop_id' => 'S0', 'stop_sequence' => 1, 'arrival_seconds' => $at(10), 'departure_seconds' => $at(10)],
            ['feed_id' => $feed->id, 'trip_id' => 'east', 'stop_id' => 'EAST', 'stop_sequence' => 2, 'arrival_seconds' => $at(18), 'departure_seconds' => $at(18)],
            ['feed_id' => $feed->id, 'trip_id' => 'east-late', 'stop_id' => 'S0', 'stop_sequence' => 1, 'arrival_seconds' => $at(20), 'departure_seconds' => $at(20)],
            ['feed_id' => $feed->id, 'trip_id' => 'east-late', 'stop_id' => 'EAST', 'stop_sequence' => 2, 'arrival_seconds' => $at(28), 'departure_seconds' => $at(28)],
        ]);

        return $feed;
    }

    public function test_nearest_departure_runs_towards_the_chosen_end(): void
    {
        $this->seedTwoDirections();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->actingAs(User::factory()->create());
        $nearest = fn (array $query) => $this->getJson('/api/transit/stops/nearest-next-departure?' . http_build_query(['lat' => 45.0, 'lon' => 26.0, 'route_ref' => 'M1', 'mode' => 'subway', ...$query]));

        try {
            // Any direction: the first train, without a direction flag.
            $nearest([])->assertOk()->assertJsonPath('departure.time', '10:05')->assertJsonMissingPath('departure.direction_matched');

            // Towards Dristor 2, spelled differently from the feed ("dristor-2"): its train, not Pantelimon's.
            $nearest(['towards' => 'dristor-2'])
                ->assertJsonPath('departure.time', '10:10')
                ->assertJsonPath('departure.headsign', 'Dristor 2')
                ->assertJsonPath('departure.direction_matched', true);
            $nearest(['towards' => 'Pantelimon'])->assertJsonPath('departure.time', '10:05');

            // No train towards that end: the first train, flagged.
            $nearest(['towards' => 'Pipera'])
                ->assertJsonPath('departure.time', '10:05')
                ->assertJsonPath('departure.direction_matched', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_trains_shown_at_the_same_minute_say_the_same_minutes_until(): void
    {
        $feed = $this->seedTwoDirections();
        // Both shown as 10:05: one at 10:05:10, the other at 10:05:50.
        DB::table('transit_stop_times')->where('feed_id', $feed->id)->where('stop_id', 'S0')->where('trip_id', 'east')
            ->update(['arrival_seconds' => 10 * 3600 + 5 * 60 + 10, 'departure_seconds' => 10 * 3600 + 5 * 60 + 10]);
        DB::table('transit_stop_times')->where('feed_id', $feed->id)->where('stop_id', 'S0')->where('trip_id', 'west')
            ->update(['arrival_seconds' => 10 * 3600 + 5 * 60 + 50, 'departure_seconds' => 10 * 3600 + 5 * 60 + 50]);
        Carbon::setTestNow('2026-10-05 10:03:10');
        $this->actingAs(User::factory()->create());
        $nearest = fn (string $towards) => $this->getJson('/api/transit/stops/nearest-next-departure?lat=45.0&lon=26.0&route_ref=M1&mode=subway&towards=' . urlencode($towards));

        try {
            // Rounding the seconds up gave "in 2 min" and "in 3 min" for the same shown time.
            $nearest('Dristor 2')->assertJsonPath('departure.time', '10:05')->assertJsonPath('departure.minutes_until', 2);
            $nearest('Pantelimon')->assertJsonPath('departure.time', '10:05')->assertJsonPath('departure.minutes_until', 2);

            // In the shown minute itself: "now".
            Carbon::setTestNow('2026-10-05 10:05:05');
            $nearest('Dristor 2')->assertJsonPath('departure.time', '10:05')->assertJsonPath('departure.minutes_until', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_trip_without_headsign_is_matched_by_its_last_stop(): void
    {
        $this->seedTwoDirections();
        Carbon::setTestNow('2026-10-05 10:12:00');

        try {
            // After 10:10, the next one towards Dristor 2 is the trip without headsign (last stop: Dristor 2).
            $this->actingAs(User::factory()->create())
                ->getJson('/api/transit/stops/nearest-next-departure?lat=45.0&lon=26.0&route_ref=M1&mode=subway&towards=Dristor%202')
                ->assertOk()
                ->assertJsonPath('departure.time', '10:20')
                ->assertJsonPath('departure.destination', 'Dristor 2')
                ->assertJsonPath('departure.direction_matched', true);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_nearest_metro_departure_interpolates_a_blank_intermediate_stop_time(): void
    {
        $feed = $this->seedFeed();
        $feed->forceFill([
            'min_lat' => 44.99, 'max_lat' => 45.01, 'min_lon' => 25.99, 'max_lon' => 26.01,
            'last_imported_at' => now(), 'import_status' => 'imported',
        ])->save();
        Carbon::setTestNow('2026-10-05 10:00:00');
        DB::table('transit_agencies')->insert([
            'feed_id' => $feed->id, 'agency_id' => 'agency', 'name' => 'Metro', 'timezone' => 'UTC',
        ]);
        DB::table('transit_calendars')->insert([
            'feed_id' => $feed->id, 'service_id' => 's', 'days_mask' => 127,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-10',
        ]);
        DB::table('transit_stops')->insert([
            ['feed_id' => $feed->id, 'stop_id' => 'PREV', 'name' => 'Previous', 'lat' => 45.0, 'lon' => 26.0002, 'location_type' => 0],
            ['feed_id' => $feed->id, 'stop_id' => 'MID', 'name' => 'Metro station', 'lat' => 45.0, 'lon' => 26.0, 'location_type' => 0],
            ['feed_id' => $feed->id, 'stop_id' => 'NEXT', 'name' => 'Next', 'lat' => 45.0, 'lon' => 25.9998, 'location_type' => 0],
        ]);
        DB::table('transit_stop_times')->where('feed_id', $feed->id)->where('trip_id', 't1')->where('stop_id', 'A/1')->update([
            'stop_id' => 'PREV', 'stop_sequence' => 1,
            'arrival_seconds' => 10 * 3600, 'departure_seconds' => 10 * 3600,
        ]);
        DB::table('transit_stop_times')->insert([
            ['feed_id' => $feed->id, 'trip_id' => 't1', 'stop_id' => 'MID', 'stop_sequence' => 2,
                'arrival_seconds' => null, 'departure_seconds' => null],
            ['feed_id' => $feed->id, 'trip_id' => 't1', 'stop_id' => 'NEXT', 'stop_sequence' => 3,
                'arrival_seconds' => 10 * 3600 + 10 * 60, 'departure_seconds' => 10 * 3600 + 10 * 60],
        ]);

        try {
            $this->actingAs(User::factory()->create())
                ->getJson('/api/transit/stops/nearest-next-departure?lat=45.0&lon=26.0&route_ref=M1&mode=subway')
                ->assertOk()
                ->assertJsonPath('departure.time', '10:05')
                ->assertJsonPath('departure.estimated', true);
        } finally {
            Carbon::setTestNow();
        }
    }
}
