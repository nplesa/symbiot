<?php

namespace Tests\Feature\Transit;

use App\Jobs\ImportTransitFeedJob;
use App\Models\User;
use App\Transit\Models\TransitFeed;
use App\Transit\Services\FeedCatalogSync;
use App\Transit\Services\FeedProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DynamicFeedDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'id,data_type,entity_type,location.country_code,location.subdivision_name,location.municipality,provider,is_official,name,note,feed_contact_email,static_reference,urls.direct_download,urls.authentication_type,urls.authentication_info,urls.api_key_parameter_name,urls.latest,urls.license,location.bounding_box.minimum_latitude,location.bounding_box.maximum_latitude,location.bounding_box.minimum_longitude,location.bounding_box.maximum_longitude,location.bounding_box.extracted_on,status,features,redirect.id,redirect.comment';

    private function catalog(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cat');
        file_put_contents($path, self::HEADER . "\n" . implode("\n", $lines) . "\n");

        return $path;
    }

    private function syncCatalog(): void
    {
        $path = $this->catalog([
            'mdb-1,gtfs,,RO,Buzău,Buzău,Transbus,True,,,,,https://feeds.test/buzau.zip,0,,,,https://lic.test,44.96,45.33,26.47,27.14,2026-01-01,active,,,',
            'mdb-2,gtfs,,GB,London,London,TfL,True,,,,,https://feeds.test/london.zip,0,,,,,51.2,51.7,-0.5,0.3,2026-01-01,active,,,',
            'mdb-3,gtfs,,RO,Cluj,Cluj-Napoca,Auth Feed,True,,,,,https://feeds.test/auth.zip,2,,,,,46.7,46.8,23.5,23.7,2026-01-01,active,,,',
            'mdb-4,gtfs,,RO,Cluj,Cluj-Napoca,Inactive,True,,,,,https://feeds.test/old.zip,0,,,,,46.7,46.8,23.5,23.7,2026-01-01,deprecated,,,',
            'mdb-1-vp,gtfs_rt,vp,RO,Buzău,Buzău,Transbus,True,,,,mdb-1,https://rt.test/vp,0,,,,,44.96,45.33,26.47,27.14,2026-01-01,active,,,',
        ]);
        app(FeedCatalogSync::class)->importCsv($path);
        unlink($path);
    }

    public function test_catalog_sync_registers_only_usable_open_feeds_with_realtime_urls(): void
    {
        $this->syncCatalog();

        $this->assertSame(['mdb-1', 'mdb-2'], TransitFeed::orderBy('source_reference')->pluck('source_reference')->all());
        $this->assertSame('https://rt.test/vp', TransitFeed::where('source_reference', 'mdb-1')->value('vehicle_positions_url'));
        $this->assertSame('pending', TransitFeed::where('source_reference', 'mdb-1')->value('import_status'));
    }

    public function test_catalog_sync_adopts_manually_registered_feeds_without_duplicating_them(): void
    {
        TransitFeed::create(['slug' => 'buzau', 'name' => 'Buzău', 'static_url' => 'https://feeds.test/buzau.zip']);

        $this->syncCatalog();

        $this->assertSame(1, TransitFeed::where('static_url', 'https://feeds.test/buzau.zip')->count());
        $this->assertSame('mdb-1', TransitFeed::where('slug', 'buzau')->value('source_reference'));
    }

    public function test_catalog_sync_deactivates_feeds_that_left_the_catalog(): void
    {
        $this->syncCatalog();
        $path = $this->catalog([
            'mdb-1,gtfs,,RO,Buzău,Buzău,Transbus,True,,,,,https://feeds.test/buzau.zip,0,,,,,44.96,45.33,26.47,27.14,2026-01-01,active,,,',
        ]);
        app(FeedCatalogSync::class)->importCsv($path);
        unlink($path);

        $this->assertTrue((bool) TransitFeed::where('source_reference', 'mdb-1')->value('active'));
        $this->assertFalse((bool) TransitFeed::where('source_reference', 'mdb-2')->value('active'));
    }

    public function test_requesting_an_area_queues_only_the_matching_feed_once(): void
    {
        $this->syncCatalog();
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/transit/coverage?lat=45.15&lon=26.82')
            ->assertOk()
            ->assertJsonCount(1, 'feeds')
            ->assertJsonPath('feeds.0.name', 'Transbus')
            ->assertJsonPath('feeds.0.status', 'queued')
            ->assertJsonPath('feeds.0.realtime', true)
            ->assertJsonPath('preparing', true);

        $this->actingAs($user)->getJson('/api/transit/coverage?lat=45.15&lon=26.82')->assertOk();

        Queue::assertPushed(ImportTransitFeedJob::class, 1);
    }

    public function test_coverage_reports_import_progress_for_the_user(): void
    {
        $this->syncCatalog();
        Queue::fake();
        $user = User::factory()->create();
        $url = '/api/transit/coverage?lat=45.15&lon=26.82';

        $this->actingAs($user)->getJson($url)
            ->assertJsonPath('feeds.0.progress.stage', 'queued')
            ->assertJsonPath('feeds.0.progress.percent', 0);

        $feed = TransitFeed::firstOrFail();
        $feed->forceFill(['import_status' => 'importing'])->save();
        app(FeedProgress::class)->report($feed, 'importing', 42, 'stop_times.txt');

        $this->actingAs($user)->getJson($url)
            ->assertJsonPath('feeds.0.progress.stage', 'importing')
            ->assertJsonPath('feeds.0.progress.percent', 42)
            ->assertJsonPath('feeds.0.progress.detail', 'stop_times.txt')
            ->assertJsonPath('preparing', true);

        $feed->forceFill(['import_status' => 'imported', 'static_hash' => 'x', 'last_imported_at' => now()])->save();

        $this->actingAs($user)->getJson($url)
            ->assertJsonPath('feeds.0.progress.percent', 100)
            ->assertJsonPath('preparing', false);
    }

    public function test_continental_feeds_are_not_matched_to_a_single_city(): void
    {
        $this->syncCatalog();
        TransitFeed::query()->update(['min_lat' => 36, 'max_lat' => 60, 'min_lon' => 2, 'max_lon' => 29]);
        Queue::fake();

        $this->actingAs(User::factory()->create())->getJson('/api/transit/coverage?lat=45.15&lon=26.82')
            ->assertJsonCount(0, 'feeds');

        Queue::assertNothingPushed();
    }

    public function test_areas_without_a_feed_are_reported_as_uncovered(): void
    {
        $this->syncCatalog();
        Queue::fake();

        $this->actingAs(User::factory()->create())->getJson('/api/transit/coverage?lat=10&lon=10')
            ->assertOk()
            ->assertJsonCount(0, 'feeds')
            ->assertJsonPath('preparing', false);

        Queue::assertNothingPushed();
    }

    public function test_stale_imported_feeds_are_refreshed_and_fresh_ones_are_not(): void
    {
        $this->syncCatalog();
        Queue::fake();
        $feed = TransitFeed::where('source_reference', 'mdb-1')->first();
        $feed->forceFill(['import_status' => 'imported', 'static_hash' => 'abc', 'last_imported_at' => now()])->save();

        $this->actingAs(User::factory()->create())->getJson('/api/transit/coverage?lat=45.15&lon=26.82')
            ->assertJsonPath('feeds.0.has_data', true);
        Queue::assertNothingPushed();

        $feed->forceFill(['last_imported_at' => now()->subDays(8)])->save();
        $this->actingAs(User::factory()->create())->getJson('/api/transit/coverage?lat=45.15&lon=26.82')->assertOk();
        Queue::assertPushed(ImportTransitFeedJob::class, 1);
    }

    public function test_coverage_requires_authentication_and_valid_coordinates(): void
    {
        $this->getJson('/api/transit/coverage?lat=45&lon=26')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/transit/coverage?lat=abc&lon=26')->assertStatus(422);
    }
}
