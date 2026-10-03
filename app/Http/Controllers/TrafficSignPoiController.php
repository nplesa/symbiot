<?php

namespace App\Http\Controllers;

class TrafficSignPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'traffic_sign';
    }
}
