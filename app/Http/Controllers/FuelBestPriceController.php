<?php

namespace App\Http\Controllers;

use App\Services\FuelPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FuelBestPriceController extends Controller
{
    public function __invoke(Request $request, FuelPriceService $prices): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:100|max:35000',
            'scope' => 'nullable|in:local,country',
            'brands' => 'nullable|string|max:500',
            'fuel' => ['required', Rule::in(array_keys(FuelPriceService::categories()))],
        ]);

        try {
            $result = ($data['scope'] ?? 'local') === 'country'
                ? $prices->bestChainsInCountry($data['fuel'])
                : $prices->bestChains(
                    (float) $data['lat'],
                    (float) $data['lon'],
                    (int) ($data['radius'] ?? 5000),
                    $data['fuel'],
                    array_values(array_filter(array_map('trim', explode(',', (string) ($data['brands'] ?? ''))))),
                );
        } catch (\RuntimeException) {
            return response()->json(['message' => 'Serviciul de prețuri nu este disponibil acum.'], 502);
        }

        return response()->json($result + ['fuel' => FuelPriceService::categories()[$data['fuel']]]);
    }
}
