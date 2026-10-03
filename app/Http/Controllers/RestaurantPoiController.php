<?php

namespace App\Http\Controllers;

class RestaurantPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'restaurant';
    }
}
