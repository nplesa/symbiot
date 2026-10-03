<?php

namespace App\Http\Controllers;

use App\Models\Route as PlannedRoute;
use App\Services\GoogleMapsRouteUrl;
use Illuminate\Http\RedirectResponse;

class GoogleMapsRouteController extends Controller
{
    public function show(PlannedRoute $route, GoogleMapsRouteUrl $googleMapsRouteUrl): RedirectResponse
    {
        abort_unless((int) $route->user_id === (int) auth()->id(), 404);

        return redirect()->away($googleMapsRouteUrl->forRoute($route));
    }
}
