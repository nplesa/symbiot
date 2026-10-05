<?php

namespace App\Console\Commands;

use App\Transit\Osm\OsmCountySync;
use App\Transit\Osm\OsmiumTools;
use Illuminate\Console\Command;

class SyncOsmCounties extends Command
{
    protected $signature = 'transit:sync-osm-counties';

    protected $description = 'Register one OSM-backed transit feed per Romanian county (fills areas without GTFS)';

    public function handle(OsmCountySync $sync, OsmiumTools $osmium): int
    {
        if (! $osmium->available()) {
            $this->error('The local OSM extract is missing. Run osm:sync-romania first.');

            return self::FAILURE;
        }

        $this->info('OSM county feeds: ' . $sync->syncFromPbf());

        return self::SUCCESS;
    }
}
