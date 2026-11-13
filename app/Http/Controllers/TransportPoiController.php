<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Services\Poi\OsmTransitRoutes;
use App\Services\Poi\PoiSearch;
use App\Services\Poi\WazePoliceAlerts;
use App\Services\PoiCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TransportPoiController extends Controller
{
    public function __construct(
        private readonly PoiSearch $poiSearch,
        private readonly WazePoliceAlerts $wazePoliceAlerts,
        private readonly OsmTransitRoutes $transitRoutes,
    ) {}

    public function nearbyForType(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['bus', 'train', 'subway', 'airport'], true), 404);
        $request->query->set('types', $type);

        return $this->nearby($request);
    }

    public function nearbyForCategory(Request $request, string $category): JsonResponse
    {
        $catalog = app(PoiCatalog::class);
        abort_unless(isset($catalog->categories()[$category]), 404);

        if (! $request->has('types')) {
            $request->query->set('types', $category);

            return $this->nearby($request);
        }

        $filters = collect(explode(',', (string) $request->query('types')))
            ->map(fn (string $type): string => trim($type))
            ->filter(fn (string $type): bool => ($catalog->filters()[$type]['type'] ?? null) === $category)
            ->unique()
            ->values();

        if ($filters->isEmpty()) {
            return response()->json([]);
        }

        $request->query->set('types', $filters->implode(','));

        return $this->nearby($request);
    }

    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:100|max:35000',
            'types' => 'nullable|string',
        ]);

        $lat = (float) $validated['lat'];
        $lon = (float) $validated['lon'];
        $radius = (int) ($validated['radius'] ?? 5000);
        $includeTransitRoutes = $request->boolean('include_routes', true);

        $catalog = app(PoiCatalog::class);
        $filters = $catalog->filters();
        $selectedFilters = $request->has('types')
            ? collect(explode(',', (string) ($validated['types'] ?? '')))
                ->map(fn ($type) => trim($type))
                ->filter(fn ($type) => isset($filters[$type]))
                ->unique()
                ->values()
                ->all()
            : $catalog->enabledTypes();

        if ($selectedFilters === []) {
            return response()->json([]);
        }

        try {
            return response()->json(
                $this->poiSearch->search($lat, $lon, $radius, $selectedFilters, $includeTransitRoutes)
            );
        } catch (\Throwable $e) {
            Log::error('POI category lookup failed', [
                'categories' => collect($selectedFilters)->map(fn (string $filter) => $filters[$filter]['type'])->unique()->values()->all(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $this->clientErrorMessage($e, 'Serviciul POI nu este disponibil momentan. Încearcă din nou.'),
            ], 500);
        }
    }

    public function wazeTrafficAlerts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|integer|min:100|max:35000',
        ]);

        try {
            return response()->json($this->wazePoliceAlerts->nearby(
                (float) $validated['lat'],
                (float) $validated['lon'],
                (int) ($validated['radius'] ?? 5000),
            ));
        } catch (\Throwable $e) {
            Log::error('Waze traffic alert lookup failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $this->clientErrorMessage($e, 'Alertele de trafic nu sunt disponibile momentan.'),
            ], 500);
        }
    }

    public function routeGeometry(int $relationId): JsonResponse
    {
        try {
            $route = $this->transitRoutes->routeGeometry($relationId);
        } catch (\Throwable $e) {
            Log::warning('OpenStreetMap transit route geometry lookup failed', [
                'relation_id' => $relationId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Could not load this route geometry from OpenStreetMap.',
            ], 502);
        }

        if ($route === null) {
            return response()->json([
                'error' => 'No route geometry is available in OpenStreetMap.',
            ], 404);
        }

        return response()->json($route);
    }

    /**
     * Upstream/config errors (Waze key, OSM HTTP status, SQL) only help while setting up the app,
     * so they are shown in debug mode; production users get a generic message.
     */
    private function clientErrorMessage(\Throwable $exception, string $fallback): string
    {
        if ($exception instanceof UserFacingException || config('app.debug')) {
            return $exception->getMessage() !== '' ? $exception->getMessage() : $fallback;
        }

        return $fallback;
    }
}
