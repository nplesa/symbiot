<?php

namespace App\Http\Controllers;

class PharmacyPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'pharmacy';
    }
}
