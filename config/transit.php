<?php

use App\Transit\Providers\GtfsStaticProvider;
use App\Transit\Providers\OsmRouteProvider;

return [
    'catalog_url' => env('TRANSIT_CATALOG_URL', 'https://files.mobilitydatabase.org/feeds_v2.csv'),

    // These agencies publish official GTFS feeds that may be omitted from a catalog snapshot.
    'preserved_gtfs_references' => array_filter(array_map('trim', explode(',', env('TRANSIT_PRESERVED_GTFS_REFERENCES', 'mdb-757,mdb-763')))),

    // Feeds are discovered by location and imported the first time an area is requested.
    'reimport_after_hours' => (int) env('TRANSIT_REIMPORT_AFTER_HOURS', 168),
    'retry_failed_after_hours' => (int) env('TRANSIT_RETRY_FAILED_AFTER_HOURS', 6),
    'importing_timeout_minutes' => (int) env('TRANSIT_IMPORTING_TIMEOUT_MINUTES', 30),
    // Feeds spanning more than this many square degrees (continental) are never auto-imported for a city.
    'max_bbox_area_degrees' => (float) env('TRANSIT_MAX_BBOX_AREA', 50),

    'bbox_margin_degrees' => (float) env('TRANSIT_BBOX_MARGIN', 0.02),
    'max_feeds_per_request' => (int) env('TRANSIT_MAX_FEEDS_PER_REQUEST', 5),
    'queue' => env('TRANSIT_QUEUE', 'default'),

    'providers' => [
        'gtfs' => GtfsStaticProvider::class,
        'osm' => OsmRouteProvider::class,
    ],

    // OSM county feeds only fill gaps: they apply when no GTFS feed up to this size covers the point.
    'osm_fallback_gtfs_area_degrees' => (float) env('TRANSIT_OSM_FALLBACK_GTFS_AREA', 5),

    'realtime' => [
        // Redis connection from config/database.php used for live vehicle positions.
        'connection' => env('TRANSIT_REDIS_CONNECTION', 'transit'),
        // Only feeds somebody looked at within this window are polled.
        'active_minutes' => (int) env('TRANSIT_RT_ACTIVE_MINUTES', 10),
        'vehicle_ttl_seconds' => (int) env('TRANSIT_RT_TTL', 120),
        // Positions older than this are treated as stale and dropped.
        'max_vehicle_age_seconds' => (int) env('TRANSIT_RT_MAX_AGE', 300),
        'failure_backoff_seconds' => (int) env('TRANSIT_RT_BACKOFF', 60),
    ],
];
