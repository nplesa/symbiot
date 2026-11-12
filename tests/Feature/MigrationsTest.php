<?php

namespace Tests\Feature;

use App\Transit\Models\TransitFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    /** Rolls back every migration from $firstMigration onwards (tables created earlier stay), then migrates again. */
    private function rollBackTo(string $firstMigration): void
    {
        $steps = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $path): string => basename($path, '.php'))
            ->filter(fn (string $name): bool => strcmp($name, $firstMigration) >= 0)
            ->count();

        $this->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();
        $this->artisan('migrate')->assertSuccessful();
    }

    public function test_tracking_index_migration_can_be_rolled_back_and_run_again(): void
    {
        // Stops right before harden_device_uuid_and_tracking_indexes, so `trackings` survives
        // and a leftover index would make the next migrate fail with a duplicate index name.
        $this->rollBackTo('2026_08_08_120000_harden_device_uuid_and_tracking_indexes');

        $this->assertTrue(DB::getSchemaBuilder()->hasIndex('trackings', 'trackings_tracking_session_id_index'));
    }

    public function test_feed_text_columns_roll_back_with_values_longer_than_255_characters(): void
    {
        TransitFeed::create(['slug' => 'long', 'name' => str_repeat('N', 400), 'static_url' => 'https://feeds.test/x.zip']);

        $this->rollBackTo('2026_10_05_110000_widen_transit_feed_text_columns');

        $this->assertSame(255, mb_strlen((string) DB::table('transit_feeds')->where('slug', 'long')->value('name')));
    }

    public function test_stop_sequences_above_the_smallint_range_are_stored_exactly(): void
    {
        $feed = TransitFeed::create(['slug' => 'big', 'name' => 'Big', 'static_url' => 'https://feeds.test/big.zip']);

        DB::table('transit_stop_times')->insert([
            'feed_id' => $feed->id, 'trip_id' => 't', 'stop_id' => 's', 'stop_sequence' => 100000,
        ]);

        $this->assertSame(100000, (int) DB::table('transit_stop_times')->value('stop_sequence'));
        $this->assertSame('integer', DB::getSchemaBuilder()->getColumnType('transit_stop_times', 'stop_sequence'));
    }
}
