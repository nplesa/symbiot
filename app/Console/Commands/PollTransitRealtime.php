<?php

namespace App\Console\Commands;

use App\Transit\Models\TransitFeed;
use App\Transit\Realtime\RealtimePoller;
use Illuminate\Console\Command;

class PollTransitRealtime extends Command
{
    protected $signature = 'transit:poll-realtime {--feed= : Poll one feed id regardless of recent activity}';

    protected $description = 'Fetch live vehicle positions (GTFS-Realtime) for feeds that users are looking at';

    public function handle(RealtimePoller $poller): int
    {
        $feeds = $this->option('feed')
            ? TransitFeed::query()->whereKey($this->option('feed'))->whereNotNull('vehicle_positions_url')->get()
            : $poller->activeFeeds();

        foreach ($feeds as $feed) {
            $count = $poller->poll($feed);
            $this->line("#{$feed->id} {$feed->name}: " . ($count === null ? 'failed/skipped' : "{$count} vehicles"));
        }

        return self::SUCCESS;
    }
}
