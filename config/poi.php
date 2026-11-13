<?php

// Provider mappings are trusted application configuration, never request input.
$fuelBrands = [
    'petrom' => ['Petrom', 'Petrom|PETROM|OMV Petrom'],
    'omv' => ['OMV', 'OMV|Omv'],
    'rompetrol' => ['Rompetrol', 'Rompetrol|ROMPETROL|Partener Rompetrol|Rompetrol Express|Rompetrol Standard'],
    'mol' => ['MOL', 'MOL|Mol'],
    'lukoil' => ['Lukoil', 'Lukoil|LUKOIL|LukOil|LUKOIL Romania'],
    'socar' => ['Socar', 'Socar|SOCAR'],
    'gazprom' => ['Gazprom', 'Gazprom|GAZPROM|Gasprom|GASPROM'],
    'oscar' => ['Oscar', 'Oscar|OSCAR|RO Oscar'],
    'peco' => ['Peco', 'Peco|PECO|Stație Peco'],
    'euroil' => ['Euroil', 'Euroil|EUROIL'],
    'octano' => ['Octano', 'Octano|OCTANO'],
    'aral' => ['Aral', 'Aral|ARAL'],
    'shell' => ['Shell', 'Shell|SHELL'],
    'agip' => ['Agip / Eni', 'Agip|AGIP|Eni|ENI'],
    'tsv' => ['TSV', 'TSV'],
    'florea_oil' => ['Florea Oil', 'Florea Oil|FLOREA OIL'],
    'smart_diesel' => ['Smart Diesel', 'Smart Diesel|SMART DIESEL'],
    'carbogaz' => ['Carbogaz', 'Carbogaz|CARBOGAZ'],
    'rbc_gas' => ['RBC Gas', 'RBC Gas|RBC GAS'],
    'icom_oil' => ['Icom Oil', 'Icom Oil|ICOM OIL'],
    'ukrnafta' => ['Ukrnafta', 'Ukrnafta|UKRNAFTA'],
    'avia' => ['Avia', 'Avia|AVIA'],
    'delta_petrol' => ['Delta Petrol', 'Delta Petrol|DELTA PETROL'],
    'as24' => ['AS 24', 'AS 24|AS24'],
];
$fuelSubcategories = [['id' => 'all', 'label' => 'All fuel stations', 'osm' => [['amenity' => 'fuel']]]];
foreach ($fuelBrands as $id => [$label, $pattern]) {
    $fuelSubcategories[] = [
        'id' => $id,
        'label' => $label,
        'osm' => [
            ['amenity' => 'fuel', 'brand' => $pattern],
            ['amenity' => 'fuel', 'operator' => $pattern],
            ['amenity' => 'fuel', 'name' => $pattern],
        ],
    ];
}
$fuelSubcategories[] = ['id' => 'lpg', 'label' => 'Autogas (LPG)', 'osm' => [['amenity' => 'fuel', 'fuel:lpg' => 'yes']]];

return [
    'default_types' => ['airport', 'bus', 'train', 'subway', 'taxi', 'hospital', 'pharmacy', 'police', 'fire', 'tourism'],
    'navigation_types' => ['fuel', 'parking', 'restaurant', 'cafe', 'lodging', 'supermarket', 'pharmacy', 'charging_station', 'police', 'speed_camera', 'speed_limit', 'traffic_sign', 'vignette_control', 'control', 'locality'],
    'osm_subcategories' => [
        'fuel' => $fuelSubcategories,
        'police' => [
            ['id' => 'police_station', 'label' => 'Police stations', 'osm' => [['amenity' => 'police']]],
            ['id' => 'traffic_filters', 'label' => 'Traffic checks', 'osm' => []],
            ['id' => 'speed_limit', 'type' => 'speed_limit', 'label' => 'Speed limits', 'osm' => [['maxspeed' => null]]],
            ['id' => 'control', 'type' => 'control', 'label' => 'Checkpoints', 'osm' => [['enforcement' => null]]],
            ['id' => 'traffic_sign', 'type' => 'traffic_sign', 'label' => 'Traffic signs', 'osm' => [['traffic_sign' => null], ['highway' => 'traffic_signals|stop|give_way']]],
            ['id' => 'locality', 'type' => 'locality', 'label' => 'Localities', 'osm' => [['place' => 'city|town|village|hamlet']]],
            ['id' => 'speed_camera', 'type' => 'speed_camera', 'label' => 'Speed cameras', 'osm' => [['highway' => 'speed_camera']]],
            ['id' => 'vignette_control', 'type' => 'vignette_control', 'label' => 'Tolls / vignette', 'osm' => [['barrier' => 'toll_booth']]],
        ],
    ],
    'categories' => [
        'fuel' => [
            'label' => 'Fuel stations', 'icon' => '⛽', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['service.vehicle.fuel'],
            'osm' => [['amenity' => 'fuel']],
        ],
        'parking' => [
            'label' => 'Parking', 'icon' => 'P', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['parking'],
            'osm' => [['amenity' => 'parking']],
        ],
        'restaurant' => [
            'label' => 'Restaurants', 'icon' => '🍴', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['catering.restaurant'],
            'osm' => [['amenity' => 'restaurant']],
        ],
        'cafe' => [
            'label' => 'Cafés', 'icon' => '☕', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['catering.cafe'],
            'osm' => [['amenity' => 'cafe']],
        ],
        'lodging' => [
            'label' => 'Lodging', 'icon' => '▣', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['accommodation'],
            'osm' => [['tourism' => 'hotel|motel|guest_house|camp_site']],
        ],
        'supermarket' => [
            'label' => 'Supermarkets', 'icon' => '🛒', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['commercial.supermarket'],
            'osm' => [['shop' => 'supermarket']],
        ],
        'airport' => [
            'label' => 'Airports', 'icon' => '✈', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['airport'],
            'osm' => [['aeroway' => 'aerodrome']],
        ],
        'bus' => [
            'label' => 'Bus stops', 'icon' => '🚌', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['public_transport.bus'],
            'osm' => [
                ['highway' => 'bus_stop'],
                ['amenity' => 'bus_station'],
                ['public_transport' => 'platform', 'bus' => 'yes'],
                ['public_transport' => 'stop_position', 'bus' => 'yes'],
            ],
        ],
        'subway' => [
            'label' => 'Metro', 'icon' => '🚇', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['public_transport.subway'],
            'osm' => [['railway' => 'subway_entrance'], ['railway' => 'station', 'station' => 'subway']],
        ],
        'train' => [
            'label' => 'Train stations', 'icon' => '🚆', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['public_transport.train'],
            'osm' => [['railway' => 'station|halt']],
        ],
        'taxi' => [
            'label' => 'Taxi', 'icon' => '🚕', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['service.taxi'],
            'osm' => [['amenity' => 'taxi']],
        ],
        'hospital' => [
            'label' => 'Hospitals', 'icon' => '✚', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['healthcare.hospital'],
            'osm' => [['amenity' => 'hospital'], ['healthcare' => 'hospital']],
        ],
        'pharmacy' => [
            'label' => 'Pharmacies', 'icon' => '✚', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['healthcare.pharmacy'],
            'osm' => [['amenity' => 'pharmacy']],
        ],
        'fire' => [
            'label' => 'Fire stations', 'icon' => '🚒', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['service.fire_station'],
            'osm' => [['amenity' => 'fire_station']],
        ],
        'tourism' => [
            'label' => 'Tourist attractions', 'icon' => '🏛', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['tourism', 'heritage', 'entertainment.museum'],
            'osm' => [['tourism' => 'attraction|museum|viewpoint|gallery|artwork'], ['historic' => 'monument|memorial|castle|ruins|archaeological_site']],
        ],
        'charging_station' => [
            'label' => 'EV charging', 'icon' => 'ϟ', 'default' => true,
            'group' => 'services', 'distance' => 500,
            'geoapify' => ['service.vehicle.charging_station'],
            'osm' => [['amenity' => 'charging_station']],
        ],
        'police' => [
            'label' => 'Police', 'icon' => null, 'default' => true,
            'group' => 'police', 'distance' => 150,
            'geoapify' => ['service.police'],
            'osm' => [
                ['amenity' => 'police'],
                ['police' => 'checkpoint'],
                ['police' => 'traffic_police'],
            ],
        ],
        'speed_camera' => [
            'label' => 'Speed cameras', 'icon' => null, 'default' => true,
            'group' => 'police', 'distance' => 150,
            'geoapify' => [],
            'osm' => [['highway' => 'speed_camera']],
        ],
        'speed_limit' => [
            'label' => 'Speed limits', 'icon' => null, 'default' => true,
            'group' => 'police', 'distance' => 150,
            'geoapify' => [],
            'osm' => [['maxspeed' => null]],
        ],
        'traffic_sign' => [
            'label' => 'Traffic signs', 'icon' => null, 'default' => true,
            'group' => 'police', 'distance' => 150,
            'geoapify' => [],
            'osm' => [['traffic_sign' => null], ['highway' => 'traffic_signals|stop|give_way']],
        ],
        'vignette_control' => [
            'label' => 'Tolls / vignette', 'icon' => null, 'default' => true,
            'group' => 'police', 'distance' => 150,
            'geoapify' => [],
            'osm' => [['barrier' => 'toll_booth']],
        ],
        'control' => [
            'label' => 'Checkpoints', 'icon' => null, 'default' => true,
            'group' => 'police', 'distance' => 150,
            'geoapify' => [],
            'osm' => [['enforcement' => null]],
        ],
        'locality' => [
            'label' => 'Localities', 'icon' => null, 'default' => true,
            'group' => 'police', 'distance' => 5000,
            'geoapify' => [],
            'osm' => [['place' => 'city|town|village|hamlet']],
        ],
    ],
];
