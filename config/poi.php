<?php

// Provider mappings are trusted application configuration, never request input.
return [
    'default_types' => ['airport', 'bus', 'train', 'subway', 'taxi', 'hospital', 'pharmacy', 'police', 'fire', 'tourism'],
    'navigation_types' => ['fuel', 'parking', 'restaurant', 'cafe', 'lodging', 'supermarket', 'pharmacy', 'charging_station', 'police', 'speed_camera', 'speed_limit', 'traffic_sign', 'vignette_control', 'control', 'locality'],
    'osm_subcategories' => [
        'police' => [
            ['id' => 'police_station', 'label' => 'Secții', 'osm' => [['amenity' => 'police']]],
            ['id' => 'traffic_filters', 'label' => 'Filtre în trafic', 'osm' => []],
            ['id' => 'speed_limit', 'type' => 'speed_limit', 'label' => 'Limite de viteză', 'osm' => [['maxspeed' => null]]],
            ['id' => 'control', 'type' => 'control', 'label' => 'Puncte de control', 'osm' => [['enforcement' => null]]],
            ['id' => 'traffic_sign', 'type' => 'traffic_sign', 'label' => 'Indicatoare', 'osm' => [['traffic_sign' => null], ['highway' => 'traffic_signals|stop|give_way']]],
            ['id' => 'locality', 'type' => 'locality', 'label' => 'Localități', 'osm' => [['place' => 'city|town|village|hamlet']]],
            ['id' => 'speed_camera', 'type' => 'speed_camera', 'label' => 'Camere de viteză', 'osm' => [['highway' => 'speed_camera']]],
            ['id' => 'vignette_control', 'type' => 'vignette_control', 'label' => 'Taxe / rovinietă', 'osm' => [['barrier' => 'toll_booth']]],
        ],
    ],
    'categories' => [
        'fuel' => [
            'label' => 'Benzinării', 'icon' => '⛽', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['service.vehicle.fuel'],
            'osm' => [['amenity' => 'fuel']],
        ],
        'parking' => [
            'label' => 'Parcări', 'icon' => 'P', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['parking'],
            'osm' => [['amenity' => 'parking']],
        ],
        'restaurant' => [
            'label' => 'Restaurante', 'icon' => '🍴', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['catering.restaurant'],
            'osm' => [['amenity' => 'restaurant']],
        ],
        'cafe' => [
            'label' => 'Cafenele', 'icon' => '☕', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['catering.cafe'],
            'osm' => [['amenity' => 'cafe']],
        ],
        'lodging' => [
            'label' => 'Cazare', 'icon' => '▣', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['accommodation'],
            'osm' => [['tourism' => 'hotel|motel|guest_house|camp_site']],
        ],
        'supermarket' => [
            'label' => 'Supermarketuri', 'icon' => '🛒', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['commercial.supermarket'],
            'osm' => [['shop' => 'supermarket']],
        ],
        'airport' => [
            'label' => 'Aeroporturi', 'icon' => '✈', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['airport'],
            'osm' => [['aeroway' => 'aerodrome']],
        ],
        'bus' => [
            'label' => 'Stații de autobuz', 'icon' => '🚌', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['public_transport.bus'],
            'osm' => [
                ['highway' => 'bus_stop'],
                ['amenity' => 'bus_station'],
                ['public_transport' => 'platform', 'bus' => 'yes'],
                ['public_transport' => 'stop_position', 'bus' => 'yes'],
            ],
        ],
        'subway' => [
            'label' => 'Metrou', 'icon' => '🚇', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['public_transport.subway'],
            'osm' => [['railway' => 'subway_entrance'], ['railway' => 'station', 'station' => 'subway']],
        ],
        'train' => [
            'label' => 'Gări', 'icon' => '🚆', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['public_transport.train'],
            'osm' => [['railway' => 'station|halt']],
        ],
        'taxi' => [
            'label' => 'Taxi', 'icon' => '🚕', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['service.taxi'],
            'osm' => [['amenity' => 'taxi']],
        ],
        'hospital' => [
            'label' => 'Spitale', 'icon' => '✚', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['healthcare.hospital'],
            'osm' => [['amenity' => 'hospital'], ['healthcare' => 'hospital']],
        ],
        'pharmacy' => [
            'label' => 'Farmacii', 'icon' => '✚', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['healthcare.pharmacy'],
            'osm' => [['amenity' => 'pharmacy']],
        ],
        'fire' => [
            'label' => 'Pompieri', 'icon' => '🚒', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['service.fire_station'],
            'osm' => [['amenity' => 'fire_station']],
        ],
        'tourism' => [
            'label' => 'Obiective turistice', 'icon' => '🏛', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['tourism', 'heritage', 'entertainment.museum'],
            'osm' => [['tourism' => 'attraction|museum|viewpoint|gallery|artwork'], ['historic' => 'monument|memorial|castle|ruins|archaeological_site']],
        ],
        'charging_station' => [
            'label' => 'Încărcare electrică', 'icon' => 'ϟ', 'default' => true,
            'group' => 'Servicii și opriri', 'distance' => 500,
            'geoapify' => ['service.vehicle.charging_station'],
            'osm' => [['amenity' => 'charging_station']],
        ],
        'police' => [
            'label' => 'Poliție', 'icon' => null, 'default' => true,
            'group' => 'Poliție', 'distance' => 150,
            'geoapify' => ['service.police'],
            'osm' => [
                ['amenity' => 'police'],
                ['police' => 'checkpoint'],
                ['police' => 'traffic_police'],
            ],
        ],
        'speed_camera' => [
            'label' => 'Camere de viteză', 'icon' => null, 'default' => true,
            'group' => 'Poliție', 'distance' => 150,
            'geoapify' => [],
            'osm' => [['highway' => 'speed_camera']],
        ],
        'speed_limit' => [
            'label' => 'Limite de viteză', 'icon' => null, 'default' => true,
            'group' => 'Poliție', 'distance' => 150,
            'geoapify' => [],
            'osm' => [['maxspeed' => null]],
        ],
        'traffic_sign' => [
            'label' => 'Indicatoare', 'icon' => null, 'default' => true,
            'group' => 'Poliție', 'distance' => 150,
            'geoapify' => [],
            'osm' => [['traffic_sign' => null], ['highway' => 'traffic_signals|stop|give_way']],
        ],
        'vignette_control' => [
            'label' => 'Taxe / rovinietă', 'icon' => null, 'default' => true,
            'group' => 'Poliție', 'distance' => 150,
            'geoapify' => [],
            'osm' => [['barrier' => 'toll_booth']],
        ],
        'control' => [
            'label' => 'Puncte de control', 'icon' => null, 'default' => true,
            'group' => 'Poliție', 'distance' => 150,
            'geoapify' => [],
            'osm' => [['enforcement' => null]],
        ],
        'locality' => [
            'label' => 'Localități', 'icon' => null, 'default' => true,
            'group' => 'Poliție', 'distance' => 5000,
            'geoapify' => [],
            'osm' => [['place' => 'city|town|village|hamlet']],
        ],
    ],
];
