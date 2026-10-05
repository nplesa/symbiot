<?php

namespace App\Transit\Realtime;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/** Live vehicle positions, one Redis hash per feed. They expire on their own. */
class VehicleStore
{
    private function redis(): Connection
    {
        return Redis::connection(config('transit.realtime.connection'));
    }

    /** @param list<array<string, mixed>> $vehicles */
    public function replace(int $feedId, array $vehicles): void
    {
        $key = $this->key($feedId);
        $ttl = (int) config('transit.realtime.vehicle_ttl_seconds');

        $this->redis()->transaction(function ($tx) use ($key, $vehicles, $ttl): void {
            $tx->del($key);
            if ($vehicles !== []) {
                $fields = [];
                foreach ($vehicles as $vehicle) {
                    $fields[(string) $vehicle['id']] = json_encode($vehicle, JSON_THROW_ON_ERROR);
                }
                $tx->hmset($key, $fields);
                $tx->expire($key, $ttl);
            }
            $tx->setex($key . ':updated', $ttl, (string) time());
        });
    }

    /** @return list<array<string, mixed>> */
    public function all(int $feedId): array
    {
        $raw = $this->redis()->hgetall($this->key($feedId)) ?: [];

        return array_values(array_map(fn (string $json): array => json_decode($json, true), $raw));
    }

    public function updatedAt(int $feedId): ?int
    {
        $value = $this->redis()->get($this->key($feedId) . ':updated');

        return $value === null || $value === false ? null : (int) $value;
    }

    public function forget(int $feedId): void
    {
        $this->redis()->del($this->key($feedId), $this->key($feedId) . ':updated');
    }

    public function markFailed(int $feedId): void
    {
        $this->redis()->setex($this->failKey($feedId), (int) config('transit.realtime.failure_backoff_seconds'), '1');
    }

    public function isBackedOff(int $feedId): bool
    {
        return (bool) $this->redis()->exists($this->failKey($feedId));
    }

    private function key(int $feedId): string
    {
        return "transit:vehicles:{$feedId}";
    }

    private function failKey(int $feedId): string
    {
        return "transit:vehicles:{$feedId}:failed";
    }
}
