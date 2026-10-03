<?php

namespace App\Http\Controllers;

class HospitalPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'hospital';
    }
}
