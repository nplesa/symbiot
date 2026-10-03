<?php

namespace App\Http\Controllers;

class AirportPoiController extends TransitModePoiController
{
    protected function mode(): string
    {
        return 'airport';
    }
}
