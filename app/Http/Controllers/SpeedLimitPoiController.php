<?php

namespace App\Http\Controllers;

class SpeedLimitPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'speed_limit';
    }
}
