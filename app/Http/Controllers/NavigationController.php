<?php

namespace App\Http\Controllers;

use App\Models\Route as PlannedRoute;
use App\Services\PoiCatalog;
use Illuminate\Contracts\View\View;

class NavigationController extends Controller
{
    public function show(PlannedRoute $route): View
    {
        abort_unless((int) $route->user_id === (int) auth()->id(), 404);

        return view('navigation.index', [
            'route' => $route,
            'poiCategories' => app(PoiCatalog::class)->navigation(),
        ]);
    }
}
