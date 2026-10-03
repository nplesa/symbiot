<?php

namespace App\Http\Controllers;

class PolicePoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'police';
    }
}
