<?php

namespace App\Http\Controllers;

class TaxiPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'taxi';
    }
}
