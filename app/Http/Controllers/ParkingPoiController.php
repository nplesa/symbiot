<?php

namespace App\Http\Controllers;

class ParkingPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'parking';
    }
}
