<?php

namespace Tests\Feature\Transit;

use App\Jobs\ImportTransitFeedJob;
use App\Models\User;
use App\Support\PublicUrlGuard;
use App\Transit\Models\TransitFeed;
use App\Transit\Providers\GtfsStaticProvider;
use App\Transit\Realtime\RealtimePoller;
use App\Transit\Realtime\VehicleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class TransitImportSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** Small feeds covering distinct, non-overlapping points around 45.15 / 26.82. */
    private function makeFeeds(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            TransitFeed::create([
                'slug' => "feed-{$i}",
                'name' => "Feed {$i}",
                'provider' => 'gtfs',
                'static_url' => "https://feeds.test/{$i}.zip",
                'import_status' => 'pending',
                'active' => true,
                'min_lat' => 45.0 + $i, 'max_lat' => 45.3 + $i,
                'min_lon' => 26.7, 'max_lon' => 27.0,
            ]);
        }
    }

    private function coverage(User $user, int $index): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->getJson('/api/transit/coverage?lat=' . (45.15 + $index) . '&lon=26.82');
    }

    public function test_a_user_can_only_trigger_a_limited_number_of_first_time_imports_per_day(): void
    {
        config(['transit.max_new_imports_per_user_per_day' => 2, 'transit.max_new_imports_per_hour' => 100]);
        Queue::fake();
        $this->makeFeeds(3);
        $user = User::factory()->create();

        $this->coverage($user, 0)->assertJsonPath('import_limited', false);
        $this->coverage($user, 1)->assertJsonPath('import_limited', false);
        $this->coverage($user, 2)
            ->assertJsonPath('import_limited', true)
            ->assertJsonPath('preparing', false);

        Queue::assertPushed(ImportTransitFeedJob::class, 2);
        $this->assertSame('pending', TransitFeed::where('slug', 'feed-2')->value('import_status'));

        // Another user still has their own daily allowance.
        $this->coverage(User::factory()->create(), 2)->assertJsonPath('import_limited', false);
        Queue::assertPushed(ImportTransitFeedJob::class, 3);
    }

    public function test_first_time_imports_are_capped_globally_per_hour(): void
    {
        config(['transit.max_new_imports_per_user_per_day' => 100, 'transit.max_new_imports_per_hour' => 1]);
        Queue::fake();
        $this->makeFeeds(2);

        $this->coverage(User::factory()->create(), 0)->assertJsonPath('import_limited', false);
        $this->coverage(User::factory()->create(), 1)->assertJsonPath('import_limited', true);

        Queue::assertPushed(ImportTransitFeedJob::class, 1);
    }

    public function test_refreshing_an_already_imported_feed_does_not_use_the_quota(): void
    {
        config(['transit.max_new_imports_per_user_per_day' => 0, 'transit.max_new_imports_per_hour' => 0]);
        Queue::fake();
        $this->makeFeeds(1);
        TransitFeed::query()->update([
            'import_status' => 'imported',
            'static_hash' => 'abc',
            'last_imported_at' => now()->subYear(),
        ]);

        $this->coverage(User::factory()->create(), 0)->assertJsonPath('import_limited', false);

        Queue::assertPushed(ImportTransitFeedJob::class, 1);
    }

    public function test_import_errors_are_hidden_from_users_in_production(): void
    {
        Queue::fake();
        $this->makeFeeds(1);
        TransitFeed::query()->update([
            'import_status' => 'failed',
            'import_error' => 'SQLSTATE[HY000]: secret_table at /var/www/symbiot/storage',
            'updated_at' => now(),
        ]);
        $user = User::factory()->create();

        config(['app.debug' => false]);
        $response = $this->coverage($user, 0)
            ->assertJsonPath('feeds.0.progress.detail', 'Importul datelor de transport a eșuat.');
        $this->assertStringNotContainsString('secret_table', $response->getContent());

        config(['app.debug' => true]);
        $this->coverage($user, 0)
            ->assertJsonPath('feeds.0.progress.detail', 'SQLSTATE[HY000]: secret_table at /var/www/symbiot/storage');
    }

    public function test_feed_urls_pointing_to_private_networks_are_never_fetched(): void
    {
        $this->app->instance(PublicUrlGuard::class, new PublicUrlGuard(fn (string $host): array => ['169.254.169.254']));
        Http::fake();
        $feed = TransitFeed::create(['slug' => 'ssrf', 'name' => 'SSRF', 'static_url' => 'https://metadata.internal.test/latest']);

        $this->expectException(RuntimeException::class);

        try {
            app(GtfsStaticProvider::class)->importStatic($feed);
        } finally {
            Http::assertNothingSent();
            $this->assertSame('failed', $feed->fresh()->import_status);
        }
    }

    public function test_private_and_loopback_addresses_are_rejected_by_the_guard(): void
    {
        $guard = new PublicUrlGuard(fn (string $host): array => match ($host) {
            'internal.test' => ['10.0.0.5'],
            'mixed.test' => ['203.0.113.10', '127.0.0.1'],
            default => ['203.0.113.10'],
        });

        foreach ([
            'http://127.0.0.1/admin',
            'http://[::1]/',
            'http://169.254.169.254/latest/meta-data',
            'http://192.168.1.10/',
            'https://internal.test/gtfs.zip',
            'https://mixed.test/gtfs.zip',
            'ftp://feeds.test/gtfs.zip',
        ] as $url) {
            try {
                $guard->assertPublic($url);
                $this->fail("{$url} should have been rejected.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        $guard->assertPublic('https://feeds.test/gtfs.zip');
        $this->addToAssertionCount(1);
    }

    public function test_realtime_urls_pointing_to_private_networks_are_never_polled(): void
    {
        $this->app->instance(PublicUrlGuard::class, new PublicUrlGuard(fn (string $host): array => ['127.0.0.1']));
        $this->mock(VehicleStore::class, function ($mock): void {
            $mock->shouldReceive('isBackedOff')->andReturn(false);
            $mock->shouldReceive('markFailed')->once();
            $mock->shouldNotReceive('replace');
        });
        Http::fake();
        $feed = TransitFeed::create([
            'slug' => 'rt', 'name' => 'RT', 'static_url' => 'https://feeds.test/rt.zip',
            'vehicle_positions_url' => 'http://localhost.test/vehicles',
        ]);

        $this->assertNull(app(RealtimePoller::class)->poll($feed));
        Http::assertNothingSent();
    }

    public function test_an_invalid_feed_fails_after_a_single_attempt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gz');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        // Valid archive, but stop_times.txt is missing: retrying cannot fix that.
        foreach (['stops.txt', 'routes.txt', 'trips.txt'] as $file) {
            $zip->addFromString($file, "id\n1\n");
        }
        $zip->close();
        $body = file_get_contents($path);
        unlink($path);
        Http::fake(['feeds.test/*' => fn () => Http::response($body)]);
        $feed = TransitFeed::create([
            'slug' => 'broken', 'name' => 'Broken', 'static_url' => 'https://feeds.test/broken.zip', 'active' => true,
        ]);

        ImportTransitFeedJob::dispatchSync($feed->id);

        Http::assertSentCount(1);
        $feed->refresh();
        $this->assertSame('failed', $feed->import_status);
        $this->assertStringContainsString('missing stop_times.txt', (string) $feed->import_error);
    }

    public function test_import_job_retries_and_unique_lock_cover_the_whole_run(): void
    {
        $job = new ImportTransitFeedJob(1);

        $this->assertSame(3, $job->tries);
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), $job->timeout);
        $this->assertGreaterThanOrEqual(
            $job->tries * $job->timeout + array_sum($job->backoff()),
            $job->uniqueFor
        );
    }

    public function test_oversized_downloads_and_archives_are_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gz');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach (['stops.txt', 'routes.txt', 'trips.txt'] as $file) {
            $zip->addFromString($file, "id\n1\n");
        }
        // Highly compressible: tiny download, ~2 MB once uncompressed.
        $zip->addFromString('stop_times.txt', str_repeat('0', 2 * 1024 * 1024));
        $zip->close();
        $body = file_get_contents($path);
        unlink($path);
        // A fresh response per request: a shared one would hand an exhausted body stream to the second import.
        Http::fake(['feeds.test/*' => fn () => Http::response($body)]);

        config(['transit.max_download_mb' => 0]);
        $feed = TransitFeed::create(['slug' => 'big', 'name' => 'Big', 'static_url' => 'https://feeds.test/big.zip']);
        try {
            app(GtfsStaticProvider::class)->importStatic($feed);
            $this->fail('An oversized download should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exceeds the configured size limit', $exception->getMessage());
        }

        config(['transit.max_download_mb' => 300, 'transit.max_uncompressed_mb' => 1]);
        $feed = TransitFeed::create(['slug' => 'bomb', 'name' => 'Bomb', 'static_url' => 'https://feeds.test/bomb.zip']);
        try {
            app(GtfsStaticProvider::class)->importStatic($feed);
            $this->fail('An oversized archive should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('uncompressed GTFS feed exceeds', $exception->getMessage());
        }
    }
}
