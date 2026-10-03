<?php

namespace App\Http\Controllers;

class SubwayPoiController extends TransitModePoiController
{
    protected function mode(): string
    {
        return 'subway';
    }
}
