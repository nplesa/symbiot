<?php

namespace App\Http\Controllers;

use App\Services\CityBoundaries;
use App\Services\CityBoundaryRateLimited;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The boundary of a city for "Whole city" (stored after the first Geoapify answer, see CityBoundaries). */
class CityBoundaryController extends Controller
{
    public function __invoke(Request $request, CityBoundaries $boundaries): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required_without:id', 'numeric', 'between:-90,90'],
            'lon' => ['required_without:id', 'numeric', 'between:-180,180'],
            'id' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);
        if (blank(config('services.geoapify.key'))) {
            return response()->json(['error' => 'Geoapify nu este configurat.'], 503);
        }

        try {
            $feature = $boundaries->find(
                $validated['id'] ?? null,
                isset($validated['lat']) ? (float) $validated['lat'] : null,
                isset($validated['lon']) ? (float) $validated['lon'] : null,
                $validated['name'] ?? null,
                (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
            );
        } catch (CityBoundaryRateLimited $limited) {
            return response()
                ->json(['error' => 'Too many requests. Try again in a minute.'], 429)
                ->header('Retry-After', (string) max(1, $limited->retryAfterSeconds));
        } catch (\Throwable) {
            return response()->json(['error' => 'Limita orașului nu este disponibilă momentan.'], 502);
        }

        if (! is_array($feature)) {
            return response()->json(['error' => 'Nu am putut identifica limita administrativă a orașului.'], 404);
        }

        return response()->json(['feature' => $feature]);
    }
}
