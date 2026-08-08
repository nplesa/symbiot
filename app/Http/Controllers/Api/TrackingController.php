<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Tracking;
use App\Models\TrackingSession;
use App\Traits\ApiResponse;
use App\Services\TrackingSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\Tracking\StoreTrackingPointRequest;

class TrackingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TrackingSessionService $trackingSessions
    ) {}

    /**
     * Începe o sesiune de tracking.
     */
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

    /**
     * Primește o poziție GPS.
     */
    public function location(StoreTrackingPointRequest $request): JsonResponse
    {
        $data = $request->validated() + ['session_id' => $request->integer('session_id')];

        $session = $this->session($request, $data['session_id']);
        $tracking = $this->trackingSessions->addPoint($request->user(), $session, $data);

        $session->device?->touchLastSeen($data['battery'] ?? null);

        return $this->success($tracking, 'Location stored.');
    }

    /**
     * Oprește tracking-ul.
     */
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
        return $this->success(
            TrackingSession::query()
                ->where('user_id', $request->user()->id)
                ->latest('started_at')
                ->get()
        );
    }

    public function show(Request $request, TrackingSession $session): JsonResponse
    {
        $this->trackingSessions->authorize($request->user(), $session);

        return $this->success($session);
    }

    public function points(Request $request, TrackingSession $session): JsonResponse
    {
        $this->trackingSessions->authorize($request->user(), $session);

        return $this->success($session->trackings()->ordered()->get());
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

    /**
     * Returnează dispozitivul utilizatorului.
     */
    private function device(Request $request, string $uuid): Device
    {
        return Device::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    /**
     * Returnează sesiunea activă.
     */
    private function session(Request $request, int $sessionId): TrackingSession
    {
        return TrackingSession::whereKey($sessionId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->firstOrFail();
    }
}
