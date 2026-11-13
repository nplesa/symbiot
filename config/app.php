<?php

return [

    'name' => env('APP_NAME', 'Laravel'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    // Dates are stored and handled in UTC (e.g. tracked_at is converted to UTC before saving);
    // changing this would mix local and UTC timestamps in the database. Do not wire it to .env.
    'timezone' => 'UTC',

    // Time zone for dates shown to users or built into names, and the fallback for transit
    // agencies without one.
    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Europe/Bucharest'),

    // The times in routes/console.php (dailyAt('02:30') ...) are local times in this zone.
    'schedule_timezone' => env('APP_DISPLAY_TIMEZONE', 'Europe/Bucharest'),

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    'distance_number' => env('APP_DEFAULT_DISTANCE_NUMBER', 50),
    'distance_unit' => env('APP_DEFAULT_DISTANCE_UNIT', 'KM'),
];
