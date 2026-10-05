<?php

namespace Tests\Feature\Transit;

use App\Models\User;
use App\Transit\Models\TransitFeed;
use App\Transit\Realtime\GtfsRealtimeDecoder;
use App\Transit\Realtime\RealtimePoller;
use App\Transit\Realtime\VehicleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use Throwable;

class RealtimeVehiclesTest extends TestCase
{
    use RefreshDatabase;

    private function varint(int $n): string
    {
        $out = '';
        do {
            $byte = $n & 0x7F;
            $n >>= 7;
            $out .= chr($n ? $byte | 0x80 : $byte);
        } while ($n);

        return $out;
    }

    private function str(int $field, string $v): string
    {
        return $this->varint(($field << 3) | 2) . $this->varint(strlen($v)) . $v;
    }

    private function num(int $field, int $v): string
    {
        return $this->varint($field << 3) . $this->varint($v);
    }

    private function flt(int $field, float $v): string
    {
        return $this->varint(($field << 3) | 5) . pack('g', $v);
    }

    private function entity(string $id, ?float $lat, ?float $lon, ?int $timestamp = null, string $route = '11'): string
    {
        $position = ($lat !== null ? $this->flt(1, $lat) : '') . ($lon !== null ? $this->flt(2, $lon) : '') . $this->flt(3, 90.0);
        $vehicle = $this->str(1, $this->str(1, "trip-{$id}") . $this->str(5, $route))
            . $this->str(2, $position)
            . $this->num(5, $timestamp ?? time())
            . $this->str(7, 'S1')
            . $this->str(8, $this->str(1, "veh-{$id}") . $this->str(2, "Bus {$id}"));

        return $this->str(2, $this->str(1, $id) . $this->str(4, $vehicle));
    }

    private function payload(string ...$entities): string
    {
        return $this->str(1, $this->str(1, '2.0') . $this->num(3, time())) . implode('', $entities);
    }

    private function redisOrSkip(): void
    {
        try {
            Redis::connection('transit')->ping();
        } catch (Throwable) {
            $this->markTestSkipped('Redis is not available.');
        }
    }

    private function feed(): TransitFeed
    {
        $feed = TransitFeed::create([
            'slug' => 'rt', 'name' => 'RT', 'static_url' => 'https://feeds.test/s.zip', 'static_hash' => 'h',
            'vehicle_positions_url' => 'https://rt.test/vp', 'import_status' => 'imported',
            'min_lat' => 45.0, 'max_lat' => 45.3, 'min_lon' => 26.6, 'max_lon' => 27.0, 'requested_at' => now(),
        ]);
        DB::table('transit_routes')->insert(['feed_id' => $feed->id, 'route_id' => '11', 'short_name' => 'L11', 'route_type' => 3, 'color' => 'AA0000']);

        return $feed;
    }

    protected function tearDown(): void
    {
        try {
            $store = app(VehicleStore::class);
            TransitFeed::query()->pluck('id')->each(fn ($id) => $store->forget($id));
            Redis::connection('transit')->del('transit:vehicles:1:failed');
        } catch (Throwable) {
        }
        parent::tearDown();
    }

    public function test_decoder_reads_vehicle_positions_and_ignores_invalid_ones(): void
    {
        $vehicles = (new GtfsRealtimeDecoder)->vehicles($this->payload(
            $this->entity('a', 45.15, 26.82),
            $this->entity('b', null, 26.82),
            $this->entity('c', 0.0, 0.0),
        ));

        $this->assertCount(1, $vehicles);
        $this->assertSame('veh-a', $vehicles[0]['id']);
        $this->assertSame('Bus a', $vehicles[0]['label']);
        $this->assertSame('11', $vehicles[0]['route_id']);
        $this->assertSame('trip-a', $vehicles[0]['trip_id']);
        $this->assertEqualsWithDelta(45.15, $vehicles[0]['lat'], 0.0001);
        $this->assertEqualsWithDelta(90.0, $vehicles[0]['bearing'], 0.01);
    }

    public function test_decoder_rejects_truncated_payloads(): void
    {
        $this->expectException(\RuntimeException::class);

        (new GtfsRealtimeDecoder)->vehicles(substr($this->payload($this->entity('a', 45.1, 26.8)), 0, 12));
    }

    public function test_poller_stores_fresh_vehicles_in_redis_with_route_details(): void
    {
        $this->redisOrSkip();
        $feed = $this->feed();
        Http::fake(['rt.test/*' => Http::response($this->payload(
            $this->entity('a', 45.15, 26.82),
            $this->entity('old', 45.16, 26.83, time() - 3600),
        ))]);

        $this->assertSame(1, app(RealtimePoller::class)->poll($feed));

        $stored = app(VehicleStore::class)->all($feed->id);
        $this->assertCount(1, $stored);
        $this->assertSame('L11', $stored[0]['route_name']);
        $this->assertSame('AA0000', $stored[0]['color']);
        $this->assertGreaterThan(0, Redis::connection('transit')->ttl("transit:vehicles:{$feed->id}"));
    }

    public function test_failed_polls_back_off_and_keep_previous_positions(): void
    {
        $this->redisOrSkip();
        $feed = $this->feed();
        Http::fake(['rt.test/*' => Http::sequence()
            ->push($this->payload($this->entity('a', 45.15, 26.82)))
            ->push('boom', 500)]);

        $poller = app(RealtimePoller::class);
        $this->assertSame(1, $poller->poll($feed));
        $this->assertNull($poller->poll($feed));
        $this->assertNull($poller->poll($feed));

        Http::assertSentCount(2);
        $this->assertCount(1, app(VehicleStore::class)->all($feed->id));
    }

    public function test_vehicles_api_returns_nearby_vehicles_and_marks_the_feed_as_watched(): void
    {
        $this->redisOrSkip();
        $feed = $this->feed();
        $feed->forceFill(['requested_at' => now()->subHour()])->save();
        Http::fake(['rt.test/*' => Http::response($this->payload(
            $this->entity('near', 45.15, 26.82),
            $this->entity('far', 45.29, 26.99),
        ))]);
        app(RealtimePoller::class)->poll($feed);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transit/vehicles?lat=45.15&lon=26.82&radius=2000')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonCount(1, 'vehicles')
            ->assertJsonPath('vehicles.0.route_name', 'L11');

        $this->assertTrue($feed->fresh()->requested_at->gt(now()->subMinute()));
    }

    public function test_only_recently_watched_feeds_are_polled(): void
    {
        $feed = $this->feed();

        $this->assertCount(1, app(RealtimePoller::class)->activeFeeds());

        $feed->forceFill(['requested_at' => now()->subHour()])->save();
        $this->assertCount(0, app(RealtimePoller::class)->activeFeeds());
    }

    public function test_vehicles_api_requires_authentication(): void
    {
        $this->getJson('/api/transit/vehicles?lat=45&lon=26')->assertUnauthorized();
    }
}
