<?php

namespace App\Services\Rail;

use App\Support\GeoDistance;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Worldwide train departures and arrivals from Transitous (https://transitous.org, MOTIS API).
 * Usage policy: open-source, non-commercial, light on resources, User-Agent with a contact,
 * and a visible link to https://transitous.org/sources/ wherever the data is shown.
 *
 * @phpstan-type RailStop array{id: string, name: string, lat: float, lon: float, timezone: ?string}
 * @phpstan-type BoardEntry array{time: ?string, scheduled_time: ?string, delay_minutes: ?int, realtime: bool, cancelled: bool, train: string, number: ?string, category: string, place: ?string, track: ?string, scheduled_track: ?string, operator: ?string, operator_url: ?string}
 */
class TransitousClient
{
    /** Train modes only: no S-Bahn/suburban, subway, tram, bus or coach. */
    public const RAIL_MODES = ['HIGHSPEED_RAIL', 'LONG_DISTANCE', 'NIGHT_RAIL', 'REGIONAL_FAST_RAIL', 'REGIONAL_RAIL'];

    /** The clicked station marker (OSM/Geoapify) and the Transitous stop can be this far apart. */
    public const MAX_STATION_DISTANCE_METERS = 1000;

    public function enabled(): bool
    {
        return (bool) config('services.transitous.enabled', true);
    }

    /**
     * The railway station nearest to a point, cached for a day (also when nothing is found).
     *
     * @return RailStop|null
     */
    public function nearestStation(float $lat, float $lon): ?array
    {
        $key = sprintf('transitous:station:v1:%.4f:%.4f', $lat, $lon);

        $cached = Cache::remember($key, now()->addDay(), function () use ($lat, $lon): array {
            $response = $this->request()->get('/v1/reverse-geocode', [
                'place' => sprintf('%.6f,%.6f', $lat, $lon),
                'type' => 'STOP',
            ]);
            if (! $response->successful() || ! is_array($response->json())) {
                throw new \RuntimeException('Transitous reverse geocoding returned HTTP ' . $response->status() . '.');
            }

            return ['station' => $this->pickRailStop($response->json(), $lat, $lon)];
        });

        return $cached['station'];
    }

    /**
     * The next trains leaving or arriving at a station, cached for a minute (real-time data).
     *
     * @param  'departures'|'arrivals'  $type
     * @return list<BoardEntry>
     */
    public function board(string $stopId, string $type, int $limit = 12): array
    {
        $key = 'transitous:board:v3:' . $type . ':' . $limit . ':' . sha1($stopId);

        return Cache::remember($key, now()->addMinute(), function () use ($stopId, $type, $limit): array {
            $response = $this->request()->get('/v5/stoptimes', [
                'stopId' => $stopId,
                'n' => $limit,
                'mode' => implode(',', self::RAIL_MODES),
                'arriveBy' => $type === 'arrivals' ? 'true' : 'false',
                // arriveBy=true searches backwards (EARLIER) by default; we want the next arrivals.
                'direction' => 'LATER',
            ]);
            $stopTimes = $response->json('stopTimes');
            if (! $response->successful() || ! is_array($stopTimes)) {
                throw new \RuntimeException('Transitous stop times returned HTTP ' . $response->status() . '.');
            }

            return array_values(array_map(
                fn (array $stopTime): array => $this->entry($stopTime, $type),
                array_filter($stopTimes, 'is_array')
            ));
        });
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.transitous.base_url'), '/'))
            ->withUserAgent((string) config('services.transitous.user_agent'))
            ->connectTimeout(3)
            ->timeout((int) config('services.transitous.timeout', 8))
            ->acceptJson();
    }

    /**
     * @param  array<mixed>  $matches  reverse-geocode results, nearest first
     * @return RailStop|null
     */
    private function pickRailStop(array $matches, float $lat, float $lon): ?array
    {
        $best = null;
        foreach ($matches as $match) {
            if (! is_array($match) || ! isset($match['id'], $match['lat'], $match['lon'])
                || array_intersect((array) ($match['modes'] ?? []), self::RAIL_MODES) === []) {
                continue;
            }

            $meters = GeoDistance::meters($lat, $lon, (float) $match['lat'], (float) $match['lon']);
            if ($meters <= self::MAX_STATION_DISTANCE_METERS && ($best === null || $meters < $best['meters'])) {
                $best = [
                    'meters' => $meters,
                    'stop' => [
                        'id' => (string) $match['id'],
                        'name' => (string) ($match['name'] ?? ''),
                        'lat' => (float) $match['lat'],
                        'lon' => (float) $match['lon'],
                        'timezone' => isset($match['tz']) ? (string) $match['tz'] : null,
                    ],
                ];
            }
        }

        return $best['stop'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $stopTime
     * @return BoardEntry
     */
    private function entry(array $stopTime, string $type): array
    {
        $place = is_array($stopTime['place'] ?? null) ? $stopTime['place'] : [];
        $field = $type === 'arrivals' ? 'arrival' : 'departure';
        $time = $this->string($place[$field] ?? null);
        $scheduled = $this->string($place['scheduled' . ucfirst($field)] ?? null);
        $realtime = (bool) ($stopTime['realTime'] ?? false);

        $delay = null;
        if ($realtime && $time !== null && $scheduled !== null) {
            $delay = (int) round((strtotime($time) - strtotime($scheduled)) / 60);
        }

        $otherEnd = $type === 'arrivals' ? ($stopTime['tripFrom'] ?? null) : ($stopTime['tripTo'] ?? null);
        $placeName = $type === 'departures'
            ? $this->string($stopTime['headsign'] ?? null) ?? $this->string(is_array($otherEnd) ? ($otherEnd['name'] ?? null) : null)
            : $this->string(is_array($otherEnd) ? ($otherEnd['name'] ?? null) : null);

        return [
            'time' => $time,
            'scheduled_time' => $scheduled,
            'delay_minutes' => $delay,
            'realtime' => $realtime,
            'cancelled' => (bool) ($stopTime['cancelled'] ?? false) || (bool) ($stopTime['tripCancelled'] ?? false),
            'train' => $this->string($stopTime['displayName'] ?? null) ?? $this->string($stopTime['routeShortName'] ?? null) ?? '',
            'number' => $this->string($stopTime['tripShortName'] ?? null),
            'category' => (string) ($stopTime['mode'] ?? ''),
            'place' => $placeName,
            'track' => $this->string($place['track'] ?? null),
            'scheduled_track' => $this->string($place['scheduledTrack'] ?? null),
            'operator' => $this->string($stopTime['agencyName'] ?? null),
            // Where to buy a ticket: the operator's site (third-party data, http(s) only).
            'operator_url' => $this->httpUrl($stopTime['agencyUrl'] ?? null),
        ];
    }

    private function httpUrl(mixed $value): ?string
    {
        $url = $this->string($value);
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
