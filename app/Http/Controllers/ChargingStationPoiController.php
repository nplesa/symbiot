<?php

namespace App\Http\Controllers;

class ChargingStationPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'charging_station';
    }
}
