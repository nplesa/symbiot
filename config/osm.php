<?php

return [
    'romania' => [
        'extract_url' => env('OSM_ROMANIA_EXTRACT_URL', 'https://download.geofabrik.de/europe/romania-latest.osm.pbf'),
        'updates_url' => env('OSM_ROMANIA_UPDATES_URL', 'https://download.geofabrik.de/europe/romania-updates'),
        'data_path' => env('OSM_DATA_PATH', storage_path('app/osm/romania')),
        'osmium_binary' => env('OSMIUM_BINARY', 'osmium'),
        'freshness_hours' => (int) env('OSM_DATA_FRESHNESS_HOURS', 36),
    ],
];
