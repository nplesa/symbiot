<?php

namespace App\Http\Controllers;

class CafePoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'cafe';
    }
}
