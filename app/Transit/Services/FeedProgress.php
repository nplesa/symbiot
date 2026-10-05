<?php

namespace App\Transit\Services;

use App\Transit\Models\TransitFeed;
use Illuminate\Support\Facades\Cache;

/**
 * Import progress lives in the cache (not in the import transaction), so web
 * requests from other connections can see it while the queue worker is busy.
 */
class FeedProgress
{
    private float $lastWrite = 0.0;

    public function report(TransitFeed|int $feed, string $stage, float $percent, ?string $detail = null, bool $force = true): void
    {
        $now = microtime(true);
        if (! $force && $now - $this->lastWrite < 0.7) {
            return;
        }
        $this->lastWrite = $now;

        Cache::put($this->key($feed), [
            'stage' => $stage,
            'percent' => (int) max(0, min(100, round($percent))),
            'detail' => $detail,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(2));
    }

    /** @return array{stage: string, percent: int, detail: ?string, updated_at: ?string}|null */
    public function get(TransitFeed|int $feed): ?array
    {
        return Cache::get($this->key($feed));
    }

    public function clear(TransitFeed|int $feed): void
    {
        Cache::forget($this->key($feed));
    }

    private function key(TransitFeed|int $feed): string
    {
        return 'transit:progress:' . ($feed instanceof TransitFeed ? $feed->id : $feed);
    }
}
