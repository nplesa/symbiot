<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class TransitModePoiController extends Controller
{
    abstract protected function mode(): string;

    public function __invoke(Request $request, TransportPoiController $transportPoiController): JsonResponse
    {
        return $transportPoiController->nearbyForType($request, $this->mode());
    }
}
