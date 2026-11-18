<?php

namespace Tests\Feature\Transit;

use App\Jobs\PurgeFeedDataJob;
use App\Models\User;
use App\Transit\Models\TransitFeed;
use App\Transit\Providers\GtfsStaticProvider;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use ZipArchive;

/**
 * GTFS imports write the new data under a shadow feed row in committed batches, then switch the
 * public feed to it (data_feed_id) and purge the replaced data in batches.
 */
class GtfsShadowImportTest extends TestCase
{
    use RefreshDatabase;

    private ?string $served = null;

    private bool $fakeRegistered = false;

    private function zipBody(string $routeName = '1'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gz');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('agency.txt', "agency_id,agency_name,agency_url,agency_timezone\nA,Test,https://x.test,Europe/Bucharest\n");
        $zip->addFromString('stops.txt', "stop_id,stop_name,stop_lat,stop_lon\nS1,Gara,45.15,26.82\nS2,Centru,45.16,26.83\n");
        $zip->addFromString('routes.txt', "route_id,agency_id,route_short_name,route_long_name,route_type\nR1,A,{$routeName},Linia,3\n");
        $zip->addFromString('trips.txt', "route_id,service_id,trip_id,trip_headsign\nR1,WK,T1,Centru\n");
        $zip->addFromString('stop_times.txt', "trip_id,arrival_time,departure_time,stop_id,stop_sequence\nT1,08:00:00,08:00:00,S1,1\nT1,08:10:00,08:10:00,S2,2\n");
        $zip->addFromString('calendar.txt', "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nWK,1,1,1,1,1,1,1,20260101,20271231\n");
        $zip->close();
        $body = file_get_contents($path);
        unlink($path);

        return $body;
    }

    private function serve(string $body): void
    {
        $this->served = $body;
        if (! $this->fakeRegistered) {
            $this->fakeRegistered = true;
            Http::fake(['feeds.test/*' => fn () => Http::response($this->served)]);
        }
    }

    private function feed(): TransitFeed
    {
        return TransitFeed::create([
            'slug' => 'shadow-test', 'name' => 'Shadow Test', 'static_url' => 'https://feeds.test/gtfs.zip',
            'min_lat' => 45.1, 'max_lat' => 45.2, 'min_lon' => 26.8, 'max_lon' => 26.9,
        ]);
    }

    private function rowsOf(int $feedId): int
    {
        return array_sum(array_map(fn (string $table): int => DB::table($table)->where('feed_id', $feedId)->count(), PurgeFeedDataJob::TABLES));
    }

    public function test_a_reimport_switches_to_the_new_data_and_purges_the_replaced_set(): void
    {
        $provider = app(GtfsStaticProvider::class);
        $feed = $this->feed();

        $this->serve($this->zipBody('1'));
        $this->assertTrue($provider->importStatic($feed));
        $first = (int) $feed->fresh()->data_feed_id;
        $this->assertNotSame($feed->id, $first);
        $this->assertSame($feed->id, (int) TransitFeed::query()->find($first)->shadow_of);
        $this->assertSame(0, $this->rowsOf($feed->id), 'The public feed row holds no data itself.');

        $this->serve($this->zipBody('7'));
        $this->assertTrue($provider->importStatic($feed->fresh()));
        $second = (int) $feed->fresh()->data_feed_id;

        $this->assertNotSame($first, $second);
        $this->assertSame('7', DB::table('transit_routes')->where('feed_id', $second)->value('short_name'));
        $this->assertSame(0, $this->rowsOf($first), 'The replaced data set is purged.');
        $this->assertNull(TransitFeed::query()->find($first), 'The replaced shadow row is removed.');
        $this->assertSame(1, DB::table('transit_routes')->count());
    }

    public function test_clients_only_ever_see_the_public_feed_id(): void
    {
        $feed = $this->feed();
        $this->serve($this->zipBody('1'));
        app(GtfsStaticProvider::class)->importStatic($feed);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/transit/stops?lat=45.15&lon=26.82&radius=3000')
            ->assertOk()
            ->assertJsonPath('stops.0.feed_id', $feed->id);

        $this->actingAs($user)
            ->getJson("/api/transit/stops/{$feed->id}/S1/routes")
            ->assertOk()
            ->assertJsonPath('routes.0.short_name', '1');
    }

    public function test_a_failed_import_keeps_serving_the_old_data_and_drops_the_partial_set(): void
    {
        $provider = app(GtfsStaticProvider::class);
        $feed = $this->feed();
        $this->serve($this->zipBody('1'));
        $provider->importStatic($feed);
        $served = (int) $feed->fresh()->data_feed_id;

        // The disk fills up while the new stop times are written.
        $this->serve($this->zipBody('7'));
        Event::listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert') && str_contains($query->sql, 'transit_stop_times')) {
                throw new \RuntimeException('Disk full');
            }
        });

        try {
            $provider->importStatic($feed->fresh());
            $this->fail('Expected the import to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Disk full', $e->getMessage());
        }

        $this->assertSame($served, (int) $feed->fresh()->data_feed_id, 'Still serving the previous data.');
        $this->assertSame('1', DB::table('transit_routes')->where('feed_id', $served)->value('short_name'));
        $this->assertSame(1, TransitFeed::query()->where('shadow_of', $feed->id)->count(), 'The partial shadow is gone.');
        $this->assertSame(1, DB::table('transit_routes')->count());
    }

    public function test_an_interrupted_import_is_cleaned_up_by_the_next_one(): void
    {
        $feed = $this->feed();
        // Left behind by a worker killed mid-import.
        $orphan = TransitFeed::query()->forceCreate(['slug' => 'shadow-orphan', 'name' => 'x', 'provider' => 'shadow', 'active' => false, 'shadow_of' => $feed->id]);
        DB::table('transit_stops')->insert(['feed_id' => $orphan->id, 'stop_id' => 'OLD', 'name' => 'Old', 'lat' => 45.15, 'lon' => 26.82]);

        $this->serve($this->zipBody('1'));
        app(GtfsStaticProvider::class)->importStatic($feed);

        $this->assertNull(TransitFeed::query()->find($orphan->id));
        $this->assertSame(0, DB::table('transit_stops')->where('stop_id', 'OLD')->count());
        $this->assertSame(2, DB::table('transit_stops')->count());
    }

    public function test_the_purge_job_never_deletes_the_data_being_served(): void
    {
        $feed = $this->feed();
        $this->serve($this->zipBody('1'));
        app(GtfsStaticProvider::class)->importStatic($feed);
        $served = (int) $feed->fresh()->data_feed_id;

        PurgeFeedDataJob::dispatchSync($served, true);

        $this->assertNotNull(TransitFeed::query()->find($served));
        $this->assertSame(2, DB::table('transit_stops')->where('feed_id', $served)->count());
    }

    public function test_a_long_purge_continues_in_a_new_job_instead_of_running_past_its_timeout(): void
    {
        $feed = $this->feed();
        $shadowWithStops = function () use ($feed): int {
            $shadow = TransitFeed::query()->forceCreate(['slug' => 'shadow-' . uniqid(), 'name' => 'x', 'provider' => 'shadow', 'active' => false, 'shadow_of' => $feed->id]);
            DB::table('transit_stops')->insert(array_map(
                fn (int $i): array => ['feed_id' => $shadow->id, 'stop_id' => "S{$i}", 'name' => 'Old', 'lat' => 45.15, 'lon' => 26.82],
                range(1, 5)
            ));

            return $shadow->id;
        };
        $slice = function (int $shadowId): PurgeFeedDataJob {
            $job = new PurgeFeedDataJob($shadowId, true);
            $job->batchSize = 2;
            $job->sliceSeconds = 0;

            return $job;
        };

        // Run through (sync queue): the chain of slices empties the data, then removes the shadow row.
        config(['queue.default' => 'sync']);
        $first = $shadowWithStops();
        dispatch($slice($first));
        $this->assertSame(0, DB::table('transit_stops')->where('feed_id', $first)->count());
        $this->assertNull(TransitFeed::query()->find($first));

        // One slice: a batch is deleted, the rest is queued as a new job with the same settings.
        $second = $shadowWithStops();
        Queue::fake();
        $slice($second)->handle();
        $this->assertSame(3, DB::table('transit_stops')->where('feed_id', $second)->count());
        Queue::assertPushed(PurgeFeedDataJob::class, fn (PurgeFeedDataJob $next): bool => $next->dataFeedId === $second
            && $next->deleteShadowRow && $next->batchSize === 2 && $next->sliceSeconds === 0);
        $this->assertNotNull(TransitFeed::query()->find($second), 'The shadow row stays until its data is gone.');
    }

    public function test_poi_availability_sees_the_stops_of_the_served_data(): void
    {
        Cache::flush();
        $feed = $this->feed();
        $this->serve($this->zipBody('1'));
        app(GtfsStaticProvider::class)->importStatic($feed);
        config(['services.geoapify.key' => 'test-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['features' => []])]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/poi/subcategories?lat=45.15&lon=26.82&radius=2000')
            ->assertOk()
            ->assertJsonPath('subcategories.bus', ['subcategory:bus:all']);
    }
}
