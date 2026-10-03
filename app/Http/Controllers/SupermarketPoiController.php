<?php

namespace App\Http\Controllers;

class SupermarketPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'supermarket';
    }
}
