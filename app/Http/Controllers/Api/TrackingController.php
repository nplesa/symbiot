<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTrackingSessionJob;
use App\Models\Device;
use App\Models\Tracking;
use App\Models\TrackingSession;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    use ApiResponse;

    /**
     * Începe o sesiune de tracking.
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
        ]);

        $device = $this->device($request, $data['uuid']);

        $session = TrackingSession::firstOrCreate(
            [
                'device_id' => $device->id,
                'status' => 'active',
            ],
            [
                'user_id' => $request->user()->id,
                'started_at' => now(),
            ]
        );

        $device->update([
            'last_seen' => now(),
        ]);

        return $this->success([
            'session_id' => $session->id,
            'started_at' => $session->started_at,
        ], 'Tracking started.');
    }

    /**
     * Primește o poziție GPS.
     */
    public function location(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:tracking_sessions,id'],

            'latitude' => ['required','numeric','between:-90,90'],
            'longitude' => ['required','numeric','between:-180,180'],

            'accuracy' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric'],
            'heading' => ['nullable', 'numeric'],
            'altitude' => ['nullable', 'numeric'],

            'battery' => ['nullable', 'integer', 'min:0', 'max:100'],

            'provider' => ['nullable', 'string', 'max:20'],

            'tracked_at' => ['required', 'date'],
        ]);

        $session = $this->session($request, $data['session_id']);

        Tracking::create([
            'tracking_session_id' => $session->id,

            'provider' => $data['provider'] ?? null,

            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],

            'accuracy' => $data['accuracy'] ?? null,
            'speed' => $data['speed'] ?? null,
            'heading' => $data['heading'] ?? null,
            'altitude' => $data['altitude'] ?? null,

            'battery' => $data['battery'] ?? null,

            'tracked_at' => $data['tracked_at'],
        ]);

        $session->device?->update([
            'last_seen' => now(),
            'battery' => $data['battery'] ?? $session->device->battery,
        ]);

        return $this->success(
            null,
            'Location stored.'
        );
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

        $endedAt = now();

        $session->update([
            'ended_at' => $endedAt,
            'status' => 'completed',
            'duration' => $session->started_at->diffInSeconds($endedAt),
        ]);

        $session->device?->update([
            'last_seen' => now(),
        ]);

        ProcessTrackingSessionJob::dispatch($session->id);

        return $this->success(
            null,
            'Tracking stopped.'
        );
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
