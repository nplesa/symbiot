<?php

namespace App\Http\Controllers;

class BusPoiController extends TransitModePoiController
{
    protected function mode(): string
    {
        return 'bus';
    }
}
