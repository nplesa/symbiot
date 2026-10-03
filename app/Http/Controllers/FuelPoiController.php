<?php

namespace App\Http\Controllers;

class FuelPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'fuel';
    }
}
