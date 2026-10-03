<?php

namespace App\Http\Controllers;

class TrainPoiController extends TransitModePoiController
{
    protected function mode(): string
    {
        return 'train';
    }
}
