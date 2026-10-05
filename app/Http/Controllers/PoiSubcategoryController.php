<?php

namespace App\Http\Controllers;

use App\Services\PoiSubcategoryAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PoiSubcategoryController extends Controller
{
    public function __invoke(Request $request, PoiSubcategoryAvailability $availability): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:100|max:35000',
        ]);

        return response()->json([
            'subcategories' => (object) $availability->around((float) $data['lat'], (float) $data['lon'], (int) ($data['radius'] ?? 5000)),
        ]);
    }
}
