<?php

namespace App\Jobs;

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

    public int $tries = 5;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

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
