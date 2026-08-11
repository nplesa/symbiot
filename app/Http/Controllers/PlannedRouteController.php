<?php

namespace App\Http\Controllers;

use App\Models\TrackingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlannedRouteController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'coordinates' => [
                'required',
                'array',
                'min:2',
                'max:' . (int) config('tracking.planned_route_max_coordinates', 10000),
            ],
            'coordinates.*' => ['required', 'array', 'size:2'],
            'coordinates.*.0' => ['required', 'numeric', 'between:-180,180'],
            'coordinates.*.1' => ['required', 'numeric', 'between:-90,90'],
        ]);

        $session = DB::transaction(function () use ($request, $data): TrackingSession {
            $now = now();

            $session = TrackingSession::create([
                'user_id' => $request->user()->id,
                'type' => 'planned',
                'source' => 'manual',
                'name' => $data['name'] ?? null,
                'status' => 'completed',
                'started_at' => $now,
                'ended_at' => $now,
                'planned_at' => $now,
                'duration' => 0,
                'distance' => 0,
                'route_geojson' => [
                    'type' => 'LineString',
                    'coordinates' => $data['coordinates'],
                ],
            ]);

            $rows = [];

            foreach ($data['coordinates'] as $sequence => $coordinate) {
                $rows[] = [
                    'tracking_session_id' => $session->id,
                    'type' => 'planned',
                    'source' => 'manual',
                    'sequence' => $sequence,
                    'latitude' => $coordinate[1],
                    'longitude' => $coordinate[0],
                    'tracked_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 1000) as $chunk) {
                $session->trackings()->insert($chunk);
            }

            return $session;
        });

        return response()->json([
            'success' => true,
            'session' => $session->load('trackings'),
        ], 201);
    }
}
