<?php

namespace App\Console\Commands;

use App\Transit\Models\TransitFeed;
use App\Transit\Providers\GtfsStaticProvider;
use Illuminate\Console\Command;

class ImportTransitFeed extends Command
{
    protected $signature = 'transit:import-gtfs
        {slug : Unique feed slug}
        {--name= : Feed display name (required when creating)}
        {--url= : Static GTFS ZIP URL (required when creating)}
        {--city= : City}
        {--country= : ISO 3166-1 alpha-2 country code}
        {--vehicle-positions= : GTFS-Realtime VehiclePositions URL}
        {--trip-updates= : GTFS-Realtime TripUpdates URL}
        {--alerts= : GTFS-Realtime Alerts URL}
        {--force : Re-import even when the archive is unchanged}';

    protected $description = 'Registers a GTFS feed and imports its static data.';

    public function handle(GtfsStaticProvider $provider): int
    {
        $feed = TransitFeed::firstOrNew(['slug' => $this->argument('slug')]);

        $values = array_filter([
            'name' => $this->option('name'),
            'static_url' => $this->option('url'),
            'city' => $this->option('city'),
            'country_code' => $this->option('country') ? strtoupper($this->option('country')) : null,
            'vehicle_positions_url' => $this->option('vehicle-positions'),
            'trip_updates_url' => $this->option('trip-updates'),
            'alerts_url' => $this->option('alerts'),
        ]);
        $feed->fill($values);

        if (! $feed->name || ! $feed->static_url) {
            $this->error('New feeds need --name and --url.');

            return self::FAILURE;
        }
        $feed->save();

        $imported = $provider->importStatic($feed, (bool) $this->option('force'));
        $this->info($imported ? "Imported feed {$feed->slug}." : "Feed {$feed->slug} is unchanged.");

        return self::SUCCESS;
    }
}
