<?php

namespace App\Console\Commands;

use App\Transit\Services\FeedCatalogSync;
use Illuminate\Console\Command;

class SyncTransitCatalog extends Command
{
    protected $signature = 'transit:sync-catalog';

    protected $description = 'Updates the local catalog of public GTFS/GTFS-Realtime feeds.';

    public function handle(FeedCatalogSync $sync): int
    {
        $result = $sync->sync();
        $this->info("Catalog synced: {$result['feeds']} feeds, {$result['realtime']} with realtime data.");

        return self::SUCCESS;
    }
}
