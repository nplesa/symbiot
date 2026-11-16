<?php

namespace App\Http\Controllers;

use App\Services\Rail\RailBoardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Departures/arrivals of the railway station the user clicked on the map, loaded on demand. */
class RailBoardController extends Controller
{
    public function __invoke(Request $request, RailBoardService $boards): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'name' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', 'in:departures,arrivals'],
        ]);

        return response()->json($boards->board(
            (float) $validated['lat'],
            (float) $validated['lon'],
            $validated['name'] ?? null,
            $validated['type'] ?? 'departures',
        ));
    }
}
