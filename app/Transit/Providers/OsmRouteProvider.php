<?php

namespace App\Transit\Providers;

use App\Transit\Contracts\TransitProvider;
use App\Transit\Models\TransitFeed;
use App\Transit\Osm\OsmiumTools;
use App\Transit\Osm\OsmRouteImporter;
use App\Transit\Services\FeedProgress;
use RuntimeException;
use Throwable;

/** Builds a feed from the local OSM extract for areas that publish no GTFS. */
class OsmRouteProvider implements TransitProvider
{
    public function __construct(
        private readonly OsmiumTools $osmium = new OsmiumTools,
        private readonly OsmRouteImporter $importer = new OsmRouteImporter,
    ) {}

    public function key(): string
    {
        return 'osm';
    }

    public function importStatic(TransitFeed $feed, bool $force = false): bool
    {
        $progress = new FeedProgress;
        $opl = tempnam(sys_get_temp_dir(), 'osmr');

        try {
            if (! $this->osmium->available()) {
                throw new RuntimeException('The local OSM extract is missing. Run osm:sync-romania first.');
            }

            $hash = hash('sha256', filemtime($this->osmium->pbf()) . '|' . filesize($this->osmium->pbf())
                . sprintf('|%s|%s|%s|%s', $feed->min_lon, $feed->min_lat, $feed->max_lon, $feed->max_lat)
                . '|' . md5(json_encode($feed->boundary)));
            if (! $force && $feed->import_status === 'imported' && $feed->static_hash === $hash) {
                $feed->forceFill(['last_imported_at' => now()])->save();

                return false;
            }

            $feed->forceFill(['import_status' => 'importing', 'import_error' => null])->save();
            $progress->report($feed, 'preparing', 3);
            $routes = $this->osmium->routesPbf();
            $this->osmium->extractOpl($routes, (float) $feed->min_lon, (float) $feed->min_lat, (float) $feed->max_lon, (float) $feed->max_lat, $opl);

            $this->importer->import($feed, $opl);

            $progress->report($feed, 'done', 100);
            $feed->forceFill(['static_hash' => $hash, 'import_status' => 'imported', 'last_imported_at' => now()])->save();

            return true;
        } catch (Throwable $e) {
            $progress->report($feed, 'failed', 0, $e->getMessage());
            $feed->forceFill(['import_status' => 'failed', 'import_error' => $e->getMessage()])->save();
            throw $e;
        } finally {
            @unlink($opl);
        }
    }
}
