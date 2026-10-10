<?php

namespace App\Transit\Services;

use App\Jobs\ImportTransitFeedJob;
use App\Transit\Models\TransitFeed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds the feeds covering a location and queues an import for any that are
 * missing or stale. Nothing is imported until someone looks at an area.
 */
class FeedDiscovery
{
    /** @return Collection<int, TransitFeed> */
    public function coverageFor(float $lat, float $lon): Collection
    {
        return $this->selectCoverage($this->candidatesInBounds($lat, $lat, $lon, $lon));
    }

    /** @return Collection<int, TransitFeed> */
    public function coverageWithinRadius(float $lat, float $lon, int $radiusMeters): Collection
    {
        $dLat = $radiusMeters / 111320;
        $dLon = $radiusMeters / (111320 * max(0.1, cos(deg2rad($lat))));

        return $this->selectCoverage($this->candidatesInBounds(
            $lat - $dLat,
            $lat + $dLat,
            $lon - $dLon,
            $lon + $dLon,
        ));
    }

    /** @param Collection<int, TransitFeed> $feeds
     *  @return Collection<int, TransitFeed>
     */
    private function selectCoverage(Collection $feeds): Collection
    {
        $limit = (float) config('transit.osm_fallback_gtfs_area_degrees');
        $hasGtfs = $feeds->contains(fn (TransitFeed $feed): bool => $feed->provider !== 'osm' && $this->area($feed) <= $limit);

        return $feeds
            ->reject(fn (TransitFeed $feed): bool => $feed->provider === 'osm' && $hasGtfs)
            ->take((int) config('transit.max_feeds_per_request'))
            ->values();
    }

    /** @return Collection<int, TransitFeed> */
    private function candidatesInBounds(float $minLat, float $maxLat, float $minLon, float $maxLon): Collection
    {
        $margin = (float) config('transit.bbox_margin_degrees');

        return TransitFeed::query()
            ->where(fn ($query) => $query
                ->where('active', true)
                ->orWhere(fn ($preserved) => $preserved
                    ->where('provider', 'gtfs')
                    ->whereIn('source_reference', config('transit.preserved_gtfs_references', []))))
            ->where('min_lat', '<=', $maxLat + $margin)
            ->where('max_lat', '>=', $minLat - $margin)
            ->where('min_lon', '<=', $maxLon + $margin)
            ->where('max_lon', '>=', $minLon - $margin)
            ->whereRaw('(max_lat - min_lat) * (max_lon - min_lon) <= ?', [(float) config('transit.max_bbox_area_degrees')])
            ->get()
            ->filter(fn (TransitFeed $feed): bool => $this->area($feed) <= (float) config('transit.max_bbox_area_degrees'))
            ->sortBy(fn (TransitFeed $feed): float => $this->area($feed))
            ->values();
    }

    /**
     * @return Collection<int, TransitFeed>
     */
    public function ensureImported(float $lat, float $lon, ?int $radiusMeters = null): Collection
    {
        $feeds = $radiusMeters === null
            ? $this->coverageFor($lat, $lon)
            : $this->coverageWithinRadius($lat, $lon, $radiusMeters);
        $catalogNeedsRefresh = $feeds->isEmpty() || $feeds->contains(
            fn (TransitFeed $feed): bool => $feed->provider === 'gtfs'
                && $feed->import_status === 'failed'
                && $feed->backup_static_url === null
        );
        if ($catalogNeedsRefresh) {
            $this->syncCatalogIfStale();
            $feeds = $radiusMeters === null
                ? $this->coverageFor($lat, $lon)
                : $this->coverageWithinRadius($lat, $lon, $radiusMeters);
        }

        foreach ($feeds as $feed) {
            $isPreservedFeed = in_array($feed->source_reference, config('transit.preserved_gtfs_references', []), true);
            if ($isPreservedFeed && ! $feed->active) {
                $attributes = ['active' => true];
                if ($feed->static_hash === null && in_array($feed->import_status, ['queued', 'importing'], true)) {
                    // A job may have been consumed while this feed was inactive and skipped by the handler.
                    $attributes['import_status'] = 'pending';
                    $attributes['import_error'] = null;
                }
                $feed->forceFill($attributes)->saveQuietly();
                $feed->refresh();
            }

            // Progress polling must stay read-only while a feed is queued/importing.
            // The importer holds foreign-key locks on this row during its bulk
            // transaction; updating requested_at here would time out and stop
            // the browser from polling progress.
            if (! in_array($feed->import_status, ['queued', 'importing'], true)) {
                $feed->forceFill(['requested_at' => now()])->saveQuietly();
            }
            if ($this->needsImport($feed) && $this->claim($feed)) {
                ImportTransitFeedJob::dispatch($feed->id)->onQueue(config('transit.queue'));
            }
        }

        return $feeds->map(fn (TransitFeed $feed): TransitFeed => $feed->fresh());
    }

    private function syncCatalogIfStale(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $lastCatalogSync = TransitFeed::query()
            ->where('provider', 'gtfs')
            ->max('catalog_synced_at');
        if ($lastCatalogSync !== null && \Illuminate\Support\Carbon::parse($lastCatalogSync)->isAfter(now()->subDay())) {
            return;
        }

        $lockKey = 'transit:catalog-sync-on-demand';
        if (! Cache::add($lockKey, true, now()->addDay())) {
            return;
        }

        try {
            app(FeedCatalogSync::class)->sync();
        } catch (Throwable $exception) {
            Cache::put($lockKey, true, now()->addHour());
            Log::warning('On-demand transit feed catalog sync failed', [
                'message' => $exception->getMessage(),
            ]);
        }
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
