<?php

namespace App\Http\Controllers;

class SpeedCameraPoiController extends CategoryPoiController
{
    protected function category(): string
    {
        return 'speed_camera';
    }
}
