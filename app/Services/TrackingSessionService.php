<?php

namespace App\Services;

use App\Jobs\ProcessTrackingSessionJob;
use App\Models\Device;
use App\Models\Tracking;
use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;

class TrackingSessionService
{
    public function start(User $user, ?int $deviceId = null): TrackingSession
    {
        return DB::transaction(function () use ($user, $deviceId): TrackingSession {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($deviceId !== null && ! Device::query()
                ->whereKey($deviceId)
                ->where('user_id', $user->id)
                ->exists()) {
                throw new AuthorizationException('The device does not belong to the authenticated user.');
            }

            $session = TrackingSession::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->whereNull('ended_at')
                ->latest('started_at')
                ->first();

            if ($session) {
                return $session;
            }

            return TrackingSession::create([
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'type' => 'gps',
                'source' => 'device',
                'status' => 'active',
                'started_at' => now(),
            ]);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addPoint(User $user, TrackingSession $session, array $data): Tracking
    {
        $this->authorize($user, $session);

        return DB::transaction(function () use ($user, $session, $data): Tracking {
            $lockedSession = TrackingSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorize($user, $lockedSession);

            if ($lockedSession->status !== 'active' || $lockedSession->ended_at !== null) {
                abort(409, 'The tracking session is not active.');
            }

            $pointData = [
                'type' => 'gps',
                'source' => 'device',
                'provider' => $data['provider'] ?? null,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'accuracy' => $data['accuracy'] ?? null,
                'speed' => $data['speed'] ?? null,
                'heading' => $data['heading'] ?? null,
                'altitude' => $data['altitude'] ?? null,
                'battery' => $data['battery'] ?? null,
                'tracked_at' => $data['tracked_at'] ?? now(),
            ];

            $existing = $lockedSession->trackings()
                ->where('tracked_at', $pointData['tracked_at'])
                ->where('latitude', $pointData['latitude'])
                ->where('longitude', $pointData['longitude'])
                ->first();

            if ($existing) {
                return $existing;
            }

            try {
                return $lockedSession->trackings()->create($pointData);
            } catch (UniqueConstraintViolationException) {
                return $lockedSession->trackings()
                    ->where('tracked_at', $pointData['tracked_at'])
                    ->where('latitude', $pointData['latitude'])
                    ->where('longitude', $pointData['longitude'])
                    ->firstOrFail();
            }
        });
    }

    public function stop(User $user, ?TrackingSession $session = null): ?TrackingSession
    {
        return DB::transaction(function () use ($user, $session): ?TrackingSession {
            $query = TrackingSession::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->whereNull('ended_at')
                ->latest('started_at')
                ->lockForUpdate();

            if ($session) {
                $this->authorize($user, $session);
                $query->whereKey($session->id);
            }

            $lockedSession = $query->first();

            if (! $lockedSession) {
                return null;
            }

            $endedAt = now();

            $lockedSession->update([
                'ended_at' => $endedAt,
                'status' => 'completed',
                'duration' => $lockedSession->started_at?->diffInSeconds($endedAt) ?? 0,
            ]);

            ProcessTrackingSessionJob::dispatch($lockedSession->id)->afterCommit();

            return $lockedSession->fresh();
        });
    }

    public function authorize(User $user, TrackingSession $session): void
    {
        if ((int) $session->user_id !== (int) $user->id) {
            throw new AuthorizationException('This tracking session does not belong to the authenticated user.');
        }
    }
}
