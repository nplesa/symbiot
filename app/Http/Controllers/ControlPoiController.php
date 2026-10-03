<?php

namespace App\Http\Controllers;

class ControlPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'control';
    }
}
