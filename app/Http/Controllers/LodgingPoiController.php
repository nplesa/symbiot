<?php

namespace App\Http\Controllers;

class LodgingPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'lodging';
    }
}
