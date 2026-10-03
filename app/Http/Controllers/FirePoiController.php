<?php

namespace App\Http\Controllers;

class FirePoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'fire';
    }
}
