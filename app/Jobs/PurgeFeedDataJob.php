<?php

namespace App\Jobs;

use App\Transit\Models\TransitFeed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the GTFS rows of a feed row in small committed batches (a replaced data set or an
 * unfinished shadow import), so no single transaction touches millions of rows. Optionally
 * removes the shadow feed row once it is empty.
 */
class PurgeFeedDataJob implements ShouldQueue
{
    use Queueable;

    /** Children first: none of them is referenced by the others through foreign keys, but keep a stable order. */
    public const TABLES = [
        'transit_shapes', 'transit_calendar_dates', 'transit_calendars', 'transit_frequencies', 'transit_stop_times',
        'transit_trips', 'transit_routes', 'transit_stops', 'transit_agencies', 'transit_fare_media',
    ];

    public const BATCH = 10000;

    public int $tries = 5;

    /** Each attempt resumes where the previous one stopped: deleted batches are already committed. */
    public int $timeout = 900;

    /**
     * Each run stops after this long and queues the rest as a new job, well inside $timeout:
     * a big feed (8.6 million stop_times took ~23 min) is purged in several short runs
     * instead of being killed by the worker and retried.
     */
    public int $sliceSeconds = 600;

    public int $batchSize = self::BATCH;

    public function __construct(public int $dataFeedId, public bool $deleteShadowRow = false) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        // Safety net: never delete the data a feed is serving right now.
        $served = DB::table('transit_feeds')
            ->whereNull('shadow_of')
            ->where(fn ($query) => $query
                ->where('data_feed_id', $this->dataFeedId)
                ->orWhere(fn ($own) => $own->whereNull('data_feed_id')->where('id', $this->dataFeedId)))
            ->exists();
        if ($served) {
            return;
        }

        $started = microtime(true);
        foreach (self::TABLES as $table) {
            do {
                $deleted = DB::table($table)->where('feed_id', $this->dataFeedId)->limit($this->batchSize)->delete();
                if ($deleted >= $this->batchSize && microtime(true) - $started >= $this->sliceSeconds) {
                    // Continue in a fresh job; the deleted batches are already committed.
                    $next = new self($this->dataFeedId, $this->deleteShadowRow);
                    $next->sliceSeconds = $this->sliceSeconds;
                    $next->batchSize = $this->batchSize;
                    dispatch($next->onQueue($this->queue));

                    return;
                }
            } while ($deleted >= $this->batchSize);
        }

        if ($this->deleteShadowRow) {
            // Only shadow rows are ever deleted here, never a public feed.
            TransitFeed::query()->whereKey($this->dataFeedId)->whereNotNull('shadow_of')->delete();
        }
    }
}
