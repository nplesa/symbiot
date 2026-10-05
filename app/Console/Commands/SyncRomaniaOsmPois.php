<?php

namespace App\Console\Commands;

use App\Services\RomaniaOsmPoiSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncRomaniaOsmPois extends Command
{
    protected $signature = 'osm:sync-romania {--full : Download a fresh Romania extract before applying updates}';

    protected $description = 'Import and synchronize Romania OSM POIs using Geofabrik replication updates';

    public function handle(RomaniaOsmPoiSyncService $syncService): int
    {
        try {
            $result = $syncService->sync((bool) $this->option('full'));
            $this->info(sprintf(
                'Romania OSM sync %s at sequence %d (%d POIs).',
                $result['status'],
                $result['sequence'],
                $result['records']
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
