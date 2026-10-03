<?php

namespace App\Services;

use App\Contracts\TrafficProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TomTomTrafficProvider implements TrafficProvider
{
    public function flowTile(int $z, int $x, int $y): string
    {
        $key = config('services.tomtom.key');
        if (blank($key)) {
            throw new RuntimeException('Traffic provider is not configured.');
        }

        return Cache::remember("traffic:tomtom:orbis-v2:{$z}:{$x}:{$y}", 60, function () use ($key, $z, $x, $y): string {
            try {
                $response = Http::withHeaders(['TomTom-Api-Key' => $key])
                    ->accept('image/png')->connectTimeout(3)->timeout(8)
                    ->get("https://api.tomtom.com/maps/orbis/traffic/flow/raster/tile/{$z}/{$x}/{$y}", [
                        'apiVersion' => 2, 'style' => 'light', 'tileSize' => 256,
                    ]);
            } catch (ConnectionException) {
                Log::warning('TomTom traffic connection failed.');
                throw new RuntimeException('Traffic provider is unavailable.');
            }

            if (! $response->successful() || ! str_starts_with($response->body(), "\x89PNG\r\n\x1a\n")) {
                Log::warning('TomTom traffic tile failed.', ['status' => $response->status()]);
                throw new RuntimeException('Traffic provider is unavailable.');
            }

            return $response->body();
        });
    }
}
