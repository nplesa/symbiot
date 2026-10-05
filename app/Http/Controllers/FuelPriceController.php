<?php

namespace App\Http\Controllers;

use App\Services\FuelPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FuelPriceController extends Controller
{
    public function __invoke(Request $request, FuelPriceService $prices): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'brand' => 'nullable|string|max:80',
        ]);

        return response()->json($prices->forLocation((float) $data['lat'], (float) $data['lon'], $data['brand'] ?? null));
    }
}
