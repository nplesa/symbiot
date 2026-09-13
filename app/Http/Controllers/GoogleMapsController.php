<?php

namespace App\Http\Controllers;

use App\Services\GoogleMapsUrlResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleMapsController extends Controller
{
    public function resolve(Request $request): JsonResponse
    {
        $url = $request->query('url');
        if (! is_string($url) || $url === '' || strlen($url) > 2048) {
            return response()->json(['message' => 'Link Google Maps invalid.'], 422);
        }

        try {
            $current = app(GoogleMapsUrlResolver::class)->resolve($url);

            return response()->json(['url' => $current]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage() ?: 'Nu am putut rezolva linkul Google Maps.'], 422);
        }
    }
}
