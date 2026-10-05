<?php

namespace App\Jobs;

use App\Transit\Models\TransitFeed;
use App\Transit\Services\ProviderRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportTransitFeedJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    public function __construct(public int $feedId) {}

    public function uniqueId(): string
    {
        return (string) $this->feedId;
    }

    public function handle(ProviderRegistry $providers): void
    {
        $feed = TransitFeed::find($this->feedId);
        if ($feed === null || ! $feed->active) {
            return;
        }

        $providers->for($feed)->importStatic($feed);
    }

    public function failed(Throwable $exception): void
    {
        TransitFeed::query()->whereKey($this->feedId)->where('import_status', '!=', 'failed')->update([
            'import_status' => 'failed',
            'import_error' => mb_substr($exception->getMessage(), 0, 1000),
            'updated_at' => now(),
        ]);
    }
}
