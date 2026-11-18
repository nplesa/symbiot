<?php

namespace App\Http\Controllers;

use App\Services\PoiSubcategoryAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PoiSubcategoryController extends Controller
{
    /**
     * phase=fast: existence checks (Geoapify, GTFS, local data), plus the categories still "pending";
     * phase=details: those pending categories (dense Geoapify categories, Overpass, Nominatim), asked in the background;
     * no phase: both combined.
     */
    public function __invoke(Request $request, PoiSubcategoryAvailability $availability): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:100|max:35000',
            'phase' => 'nullable|in:fast,details',
        ]);
        $lat = (float) $data['lat'];
        $lon = (float) $data['lon'];
        $radius = (int) ($data['radius'] ?? 5000);

        return match ($data['phase'] ?? null) {
            'fast' => response()->json([
                'subcategories' => (object) ($fast = $availability->fast($lat, $lon, $radius))['subcategories'],
                'pending' => $fast['pending'],
            ]),
            'details' => response()->json(['subcategories' => (object) $availability->details($lat, $lon, $radius)]),
            default => response()->json(['subcategories' => (object) $availability->around($lat, $lon, $radius)]),
        };
    }
}
