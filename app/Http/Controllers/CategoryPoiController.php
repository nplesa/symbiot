<?php

namespace App\Http\Controllers;

use App\Services\PoiCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class CategoryPoiController extends Controller
{
    abstract protected function category(): string;

    public function __invoke(
        Request $request,
        TransportPoiController $transportPoiController,
        PoiCatalog $catalog
    ): JsonResponse {
        $category = $this->category();
        abort_unless(isset($catalog->categories()[$category]), 404);

        return $transportPoiController->nearbyForCategory($request, $category);
    }
}
