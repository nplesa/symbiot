<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function update(Request $request)
    {
        $user = auth()->user();

        cache()->put(
            "user_location_{$user->id}",
            [
                'lat' => $request->lat,
                'lon' => $request->lon,
                'heading' => $request->heading,
                'active' => $request->active,
                'updated_at' => now()
            ],
            now()->addMinutes(5)
        );

        return response()->json([
            'success' => true
        ]);
    }
}
