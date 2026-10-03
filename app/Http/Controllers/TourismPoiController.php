<?php

namespace App\Http\Controllers;

class TourismPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'tourism';
    }
}
