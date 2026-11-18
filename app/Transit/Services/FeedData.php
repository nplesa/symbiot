<?php

namespace App\Transit\Services;

use App\Transit\Models\TransitFeed;
use Illuminate\Support\Facades\DB;

/**
 * Maps public feed ids (what the app and clients use) to the feed rows that hold the data
 * currently served (transit_feeds.data_feed_id, set when a GTFS import switches to new data),
 * and back. Feeds that were never re-imported this way hold their own data.
 */
class FeedData
{
    /** @var array<int, int> public feed id => data feed id, for this request */
    private array $dataIds = [];

    public function dataId(TransitFeed|int $feed): int
    {
        if ($feed instanceof TransitFeed) {
            return $this->dataIds[$feed->id] = (int) ($feed->data_feed_id ?? $feed->id);
        }

        return $this->dataIds($feed === 0 ? [] : [$feed])[$feed] ?? $feed;
    }

    /**
     * @param  iterable<int|string>  $publicIds
     * @return array<int, int> public feed id => data feed id
     */
    public function dataIds(iterable $publicIds): array
    {
        $ids = [];
        foreach ($publicIds as $id) {
            $ids[(int) $id] = (int) $id;
        }

        $missing = array_diff(array_keys($ids), array_keys($this->dataIds));
        if ($missing !== []) {
            foreach (DB::table('transit_feeds')->whereIn('id', $missing)->get(['id', 'data_feed_id']) as $row) {
                $this->dataIds[(int) $row->id] = (int) ($row->data_feed_id ?? $row->id);
            }
        }

        foreach ($ids as $id => $_) {
            $ids[$id] = $this->dataIds[$id] ?? $id;
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $map  public feed id => data feed id (from dataIds())
     * @return array<int, int> data feed id => public feed id
     */
    public function publicIds(array $map): array
    {
        return array_flip($map);
    }

    public function forget(): void
    {
        $this->dataIds = [];
    }
}
