<?php

namespace App\Jobs;

use App\Transit\Exceptions\GtfsFeedInvalidException;
use App\Transit\Models\TransitFeed;
use App\Transit\Services\FeedProgress;
use App\Transit\Services\ProviderRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportTransitFeedJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Must stay below queue retry_after (1200 s), or the queue would re-run a job still in progress. */
    public int $timeout = 900;

    /** Longer than every attempt plus backoff (3 × 900 s + 150 s), so a feed is never imported twice at once. */
    public int $uniqueFor = 3600;

    public function __construct(public int $feedId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        return (string) $this->feedId;
    }

    public function handle(ProviderRegistry $providers): void
    {
        $feed = TransitFeed::find($this->feedId);
        $isPreservedFeed = $feed !== null
            && in_array($feed->source_reference, config('transit.preserved_gtfs_references', []), true);
        if ($feed === null || (! $feed->active && ! $isPreservedFeed)) {
            return;
        }

        if ($isPreservedFeed && ! $feed->active) {
            $feed->forceFill(['active' => true])->saveQuietly();
        }

        try {
            $providers->for($feed)->importStatic($feed);
        } catch (GtfsFeedInvalidException $exception) {
            // Missing GTFS files or over the size limits: another attempt would fail the same way.
            $this->fail($exception);

            return;
        } catch (Throwable $exception) {
            if ($this->attempts() < $this->tries) {
                $previous = app(FeedProgress::class)->get($feed);
                app(FeedProgress::class)->report(
                    $feed,
                    'retrying',
                    (float) ($previous['percent'] ?? 0),
                    'Reîncerc importul după o eroare temporară.'
                );
                $feed->forceFill(['import_status' => 'importing'])->save();
            }

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        app(FeedProgress::class)->report($this->feedId, 'failed', 0, mb_substr($exception->getMessage(), 0, 1000));
        TransitFeed::query()->whereKey($this->feedId)->where('import_status', '!=', 'failed')->update([
            'import_status' => 'failed',
            'import_error' => mb_substr($exception->getMessage(), 0, 1000),
            'updated_at' => now(),
        ]);
    }
}
