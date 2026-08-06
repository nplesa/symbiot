<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'location' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        $user->location = $request->boolean('location');
        $user->save();

        return response()->json([
            'success' => true,
            'location' => $user->location,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = auth()->user();

        cache()->put(
            "user_location_{$user->id}",
            [
                'lat' => $request->lat,
                'lon' => $request->lon,
                'heading' => $request->heading,
                'active' => $request->active,
                'updated_at' => now(),
            ],
            now()->addMinutes(5)
        );

        return response()->json([
            'success' => true,
        ]);
    }
}
