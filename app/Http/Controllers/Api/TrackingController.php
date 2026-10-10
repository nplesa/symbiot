<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tracking\StoreTrackingPointRequest;
use App\Models\Device;
use App\Models\Tracking;
use App\Models\TrackingSession;
use App\Services\TrackingSessionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TrackingSessionService $trackingSessions
    ) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
        ]);

        $device = $this->device($request, $data['uuid']);
        $session = $this->trackingSessions->start($request->user(), $device->id);

        $device->touchLastSeen();

        return $this->success([
            'session_id' => $session->id,
            'started_at' => $session->started_at,
        ], 'Tracking started.');
    }

    public function location(StoreTrackingPointRequest $request): JsonResponse
    {
        $data = $request->validated() + ['session_id' => $request->integer('session_id')];

        $session = $this->session($request, $data['session_id']);
        $tracking = $this->trackingSessions->addPoint($request->user(), $session, $data);

        $session->device?->touchLastSeen($data['battery'] ?? null);

        return $this->success($tracking, 'Location stored.');
    }

    public function stop(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:tracking_sessions,id'],
        ]);

        $session = $this->session($request, $data['session_id']);
        $stopped = $this->trackingSessions->stop($request->user(), $session);

        return $this->success($stopped, 'Tracking stopped.');
    }

    public function status(Request $request): JsonResponse
    {
        $session = TrackingSession::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        return $this->success([
            'active' => $session !== null,
            'session' => $session,
        ]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $sessions = TrackingSession::query()
            ->where('user_id', $request->user()->id)
            ->latest('started_at')
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25);

        return $this->success($sessions);
    }

    public function show(Request $request, TrackingSession $session): JsonResponse
    {
        $this->trackingSessions->authorize($request->user(), $session);

        return $this->success($session);
    }

    public function points(Request $request, TrackingSession $session): JsonResponse
    {
        $this->trackingSessions->authorize($request->user(), $session);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $points = $session->trackings()
            ->ordered()
            ->paginate($validated['per_page'] ?? 500);

        return $this->success($points);
    }

    public function route(Request $request, TrackingSession $session): JsonResponse
    {
        $this->trackingSessions->authorize($request->user(), $session);

        $geometry = $session->route_geojson;

        if (! $geometry) {
            $geometry = [
                'type' => 'LineString',
                'coordinates' => $session->trackings()
                    ->orderBy('tracked_at')
                    ->orderBy('id')
                    ->limit(max(1, (int) config('tracking.web_points_max', 10000)))
                    ->get()
                    ->map(fn (Tracking $point): array => [
                        (float) $point->longitude,
                        (float) $point->latitude,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $this->success([
            'type' => 'Feature',
            'geometry' => $geometry,
        ]);
    }

    public function destroy(Request $request, TrackingSession $session): JsonResponse
    {
        $this->trackingSessions->authorize($request->user(), $session);
        $session->delete();

        return $this->success(null, 'Tracking session deleted.');
    }

    private function device(Request $request, string $uuid): Device
    {
        return Device::firstOrCreate(
            [
                'uuid' => $uuid,
                'user_id' => $request->user()->id,
            ],
            [
                'name' => 'Android Device',
                'platform' => 'android',
            ]
        );
    }

    private function session(Request $request, int $sessionId): TrackingSession
    {
        return TrackingSession::whereKey($sessionId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->firstOrFail();
    }
}
