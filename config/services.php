<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'tomtom' => [
        'key' => env('TOMTOM_API_KEY'),
    ],
    'geoapify' => [
        'key' => env('GEOAPIFY_KEY'),
    ],
    'waze' => [
        'url' => env('WAZE_API_URL', 'https://api.openwebninja.com/waze'),
        'key' => env('WAZE_API_KEY', env('WAZE_API')),
    ],
    'petroleum' => [
        // Intermediate certificate missing from the fuel price host's chain, relative to the project root.
        'ca_certificate' => env('PETROLEUM_CHECK', 'resources/certs/sectigo-dv-r36.pem'),
    ],
    'rail_timetable' => [
        'packages' => [
            ['package' => 'mers-tren-sntfc-cfr-calatori-s-a', 'operator' => 'CFR Călători'],
            ['package' => 'regiocalatori', 'operator' => 'Regio Călători'],
            ['package' => 'mers-tren-interregional-calatori', 'operator' => 'Interregional Călători'],
            ['package' => 'astra_trans_carpatic', 'operator' => 'Astra Trans Carpatic'],
            ['package' => 'mers-tren-softrans-s-r-l', 'operator' => 'Softrans'],
            ['package' => 'mers-tren-2024-2025-ferotrafic-tfi', 'operator' => 'Ferotrafic TFI'],
            ['package' => 'mers-tren-transferoviar-calatori-s-r-l', 'operator' => 'Transferoviar Călători'],
        ],
    ],

];
