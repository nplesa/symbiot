<?php

namespace App\Transit\Services;

use App\Jobs\ImportTransitFeedJob;
use App\Transit\Models\TransitFeed;
use Illuminate\Support\Collection;

/**
 * Finds the feeds covering a location and queues an import for any that are
 * missing or stale. Nothing is imported until someone looks at an area.
 */
class FeedDiscovery
{
    /** @return Collection<int, TransitFeed> */
    public function coverageFor(float $lat, float $lon): Collection
    {
        $feeds = $this->candidates($lat, $lon);
        $limit = (float) config('transit.osm_fallback_gtfs_area_degrees');
        $hasGtfs = $feeds->contains(fn (TransitFeed $feed): bool => $feed->provider !== 'osm' && $this->area($feed) <= $limit);

        return $feeds
            ->reject(fn (TransitFeed $feed): bool => $feed->provider === 'osm' && $hasGtfs)
            ->take((int) config('transit.max_feeds_per_request'))
            ->values();
    }

    /** @return Collection<int, TransitFeed> */
    private function candidates(float $lat, float $lon): Collection
    {
        $margin = (float) config('transit.bbox_margin_degrees');

        return TransitFeed::query()
            ->where('active', true)
            ->where('min_lat', '<=', $lat + $margin)
            ->where('max_lat', '>=', $lat - $margin)
            ->where('min_lon', '<=', $lon + $margin)
            ->where('max_lon', '>=', $lon - $margin)
            ->whereRaw('(max_lat - min_lat) * (max_lon - min_lon) <= ?', [(float) config('transit.max_bbox_area_degrees')])
            ->get()
            ->filter(fn (TransitFeed $feed): bool => $this->area($feed) <= (float) config('transit.max_bbox_area_degrees'))
            ->sortBy(fn (TransitFeed $feed): float => $this->area($feed))
            ->values();
    }

    /**
     * @return Collection<int, TransitFeed>
     */
    public function ensureImported(float $lat, float $lon): Collection
    {
        $feeds = $this->coverageFor($lat, $lon);

        foreach ($feeds as $feed) {
            $feed->forceFill(['requested_at' => now()])->saveQuietly();
            if ($this->needsImport($feed) && $this->claim($feed)) {
                ImportTransitFeedJob::dispatch($feed->id)->onQueue(config('transit.queue'));
            }
        }

        return $feeds->map(fn (TransitFeed $feed): TransitFeed => $feed->fresh());
    }

    public function needsImport(TransitFeed $feed): bool
    {
        $now = now();

        return match ($feed->import_status) {
            'pending' => true,
            'failed' => $feed->updated_at->lte($now->copy()->subHours(config('transit.retry_failed_after_hours'))),
            'imported' => $feed->last_imported_at === null
                || $feed->last_imported_at->lte($now->copy()->subHours(config('transit.reimport_after_hours'))),
            'queued', 'importing' => $feed->updated_at->lte(
                $now->copy()->subMinutes(config('transit.importing_timeout_minutes'))
            ),
            default => false,
        };
    }

    /** Atomically moves the feed to "queued" so concurrent requests dispatch a single job. */
    private function claim(TransitFeed $feed): bool
    {
        return TransitFeed::query()
            ->whereKey($feed->id)
            ->where('import_status', $feed->import_status)
            ->where('updated_at', $feed->updated_at)
            ->update(['import_status' => 'queued', 'updated_at' => now()]) === 1;
    }

    private function area(TransitFeed $feed): float
    {
        return ((float) $feed->max_lat - (float) $feed->min_lat) * ((float) $feed->max_lon - (float) $feed->min_lon);
    }
}
