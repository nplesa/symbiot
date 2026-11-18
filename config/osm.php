<?php

return [
    // Public Overpass API servers, tried in order; the next one is used when one fails.
    'overpass_endpoints' => [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
        'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
    ],
    // OpenStreetMap search; fallback for the aerodromes when every Overpass server is busy.
    // Usage policy: at most 1 request/s, results cached (see OsmAerodromes).
    'nominatim_url' => env('OSM_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),

    // Europe-only mirror; used first for European station lookups, then global mirrors.
    'overpass_europe_endpoint' => 'https://ovp.dresel.systems/api/interpreter',

    'romania' => [
        'extract_url' => env('OSM_ROMANIA_EXTRACT_URL', 'https://download.geofabrik.de/europe/romania-latest.osm.pbf'),
        'updates_url' => env('OSM_ROMANIA_UPDATES_URL', 'https://download.geofabrik.de/europe/romania-updates'),
        'data_path' => env('OSM_DATA_PATH', storage_path('app/osm/romania')),
        'osmium_binary' => env('OSMIUM_BINARY', 'osmium'),
        'freshness_hours' => (int) env('OSM_DATA_FRESHNESS_HOURS', 36),
    ],
];
