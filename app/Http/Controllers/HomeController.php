<?php

namespace App\Http\Controllers;

use App\Services\PoiCatalog;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(): View
    {
        $catalog = app(PoiCatalog::class);
        $poiCategories = $catalog->categories();

        return view('home', [
            'locations' => array_keys($poiCategories),
            'poiCategories' => $poiCategories,
            'poiSubcategories' => collect(array_keys($poiCategories))
                ->mapWithKeys(fn (string $type): array => [$type => $catalog->subcategories($type)])
                ->all(),
        ]);
    }
}
