<?php

namespace App\Http\Controllers;

class VignetteControlPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'vignette_control';
    }
}
