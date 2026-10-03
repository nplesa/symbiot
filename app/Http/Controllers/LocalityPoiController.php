<?php

namespace App\Http\Controllers;

class LocalityPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'locality';
    }
}
