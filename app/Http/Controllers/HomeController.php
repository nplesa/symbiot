<?php

namespace App\Http\Controllers;

use App\Services\PoiCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

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
        $sortByLabel = static fn (array $left, array $right): int => strcasecmp(
            Str::ascii($left['label']),
            Str::ascii($right['label'])
        );
        uasort($poiCategories, $sortByLabel);
        $poiSubcategories = collect(array_keys($poiCategories))
            ->mapWithKeys(function (string $type) use ($catalog, $sortByLabel): array {
                $subcategories = $catalog->subcategories($type);
                usort($subcategories, $sortByLabel);

                return [$type => $subcategories];
            })
            ->all();

        return view('home', [
            'locations' => array_keys($poiCategories),
            'poiCategories' => $poiCategories,
            'poiSubcategories' => $poiSubcategories,
        ]);
    }
}
