<?php

namespace App\Transit\Osm;

use App\Transit\Models\TransitFeed;
use App\Transit\Services\FeedProgress;
use Illuminate\Support\Facades\DB;

/**
 * Turns OSM public-transport route relations (OPL) into the same transit_* tables
 * GTFS uses. OSM carries no timetables, so trips only hold the ordered stops.
 */
class OsmRouteImporter
{
    private const TABLES = [
        'transit_shapes', 'transit_calendar_dates', 'transit_calendars', 'transit_stop_times',
        'transit_trips', 'transit_routes', 'transit_stops', 'transit_agencies',
    ];

    private const ROUTE_TYPES = ['tram' => 0, 'bus' => 3, 'trolleybus' => 11, 'share_taxi' => 3, 'minibus' => 3];

    /** Routes with less than this share of their stops inside the feed bbox belong to a neighbouring area. */
    private const MIN_INSIDE_SHARE = 0.5;

    public function __construct(private readonly FeedProgress $progress = new FeedProgress) {}

    /** @return array{routes: int, trips: int, stops: int, shapes: int} */
    public function import(TransitFeed $feed, string $oplPath): array
    {
        $this->progress->report($feed, 'preparing', 15);
        [$nodes, $ways, $relations] = $this->load($oplPath);

        $this->progress->report($feed, 'importing', 40, 'routes');
        $built = $this->build($feed, $nodes, $ways, $relations);

        DB::transaction(function () use ($feed, $built): void {
            foreach (self::TABLES as $table) {
                DB::table($table)->where('feed_id', $feed->id)->delete();
            }
            $steps = ['agencies' => 'transit_agencies', 'stops' => 'transit_stops', 'routes' => 'transit_routes',
                'trips' => 'transit_trips', 'calendars' => 'transit_calendars', 'stop_times' => 'transit_stop_times',
                'shapes' => 'transit_shapes'];
            $done = 0;
            foreach ($steps as $key => $table) {
                foreach (array_chunk($built[$key], 500) as $chunk) {
                    DB::table($table)->insertOrIgnore($chunk);
                }
                $this->progress->report($feed, 'importing', 50 + 48 * (++$done / count($steps)), $table, false);
            }
        });

        return [
            'routes' => count($built['routes']), 'trips' => count($built['trips']),
            'stops' => count($built['stops']), 'shapes' => count($built['shapes']),
        ];
    }

    /** @return array{0: array<int, array{0: float, 1: float, 2: string}>, 1: array<int, list<int>>, 2: list<array>} */
    private function load(string $path): array
    {
        $nodes = [];
        $ways = [];
        $relations = [];
        foreach (OplReader::read($path) as $object) {
            if ($object['type'] === 'n') {
                $nodes[$object['id']] = [$object['lon'], $object['lat'], $object['tags']['name'] ?? ''];
            } elseif ($object['type'] === 'w') {
                $ways[$object['id']] = [$object['nodes'] ?? [], $object['tags']['name'] ?? ''];
            } elseif (($object['tags']['type'] ?? '') === 'route' && isset(self::ROUTE_TYPES[$object['tags']['route'] ?? ''])) {
                $relations[] = $object;
            }
        }

        return [$nodes, $ways, $relations];
    }

    private function build(TransitFeed $feed, array $nodes, array $ways, array $relations): array
    {
        $id = $feed->id;
        $out = ['agencies' => [], 'stops' => [], 'routes' => [], 'trips' => [], 'calendars' => [], 'stop_times' => [], 'shapes' => []];
        $directions = [];

        foreach ($relations as $relation) {
            $tags = $relation['tags'];
            $stops = $this->stops($relation['members'] ?? [], $nodes, $ways);
            $pieces = $this->pieces($relation['members'] ?? [], $nodes, $ways);
            if ($stops === [] && $pieces === []) {
                continue;
            }
            if (! $this->belongsHere($feed, $relation['members'] ?? [], $stops, $pieces, $nodes, $ways)) {
                continue;
            }

            $ref = $tags['ref'] ?? '';
            $name = $tags['name'] ?? '';
            $operator = $tags['network'] ?? $tags['operator'] ?? '';
            $type = self::ROUTE_TYPES[$tags['route']];
            $routeId = 'osm-' . substr(md5($type . '|' . ($ref !== '' ? $ref : $name) . '|' . $operator), 0, 12);
            $agencyId = $operator === '' ? null : 'a-' . substr(md5($operator), 0, 10);

            if ($agencyId !== null) {
                $out['agencies'][$agencyId] ??= ['feed_id' => $id, 'agency_id' => $agencyId, 'name' => $operator, 'url' => $tags['network:website'] ?? $tags['website'] ?? null, 'timezone' => null];
            }

            $out['routes'][$routeId] ??= [
                'feed_id' => $id,
                'route_id' => $routeId,
                'agency_id' => $agencyId,
                'short_name' => $ref !== '' ? $ref : ($name !== '' ? $name : null),
                'long_name' => $name !== '' ? $name : null,
                'route_type' => $type,
                'color' => $this->color($tags['colour'] ?? null),
                'text_color' => null,
            ];

            $tripId = 'r' . $relation['id'];
            $direction = $directions[$routeId] = ($directions[$routeId] ?? -1) + 1;
            $out['trips'][$tripId] = [
                'feed_id' => $id,
                'trip_id' => $tripId,
                'route_id' => $routeId,
                'service_id' => 'osm',
                'headsign' => ($tags['to'] ?? '') !== '' ? $tags['to'] : ($name !== '' ? $name : null),
                'direction_id' => $direction % 2,
                'shape_id' => $pieces === [] ? null : $tripId,
            ];

            foreach ($stops as $sequence => $stop) {
                $out['stops'][$stop['id']] ??= [
                    'feed_id' => $id, 'stop_id' => $stop['id'], 'code' => null, 'name' => $stop['name'],
                    'lat' => $stop['lat'], 'lon' => $stop['lon'], 'location_type' => 0,
                    'parent_station' => null, 'wheelchair_boarding' => null,
                ];
                $out['stop_times'][] = [
                    'feed_id' => $id, 'trip_id' => $tripId, 'stop_id' => $stop['id'],
                    'stop_sequence' => $sequence + 1, 'arrival_seconds' => null, 'departure_seconds' => null,
                ];
            }

            foreach ($pieces as $index => $points) {
                $shapeId = $index === 0 ? $tripId : $tripId . '~' . $index;
                $out['shapes'][$shapeId] = [
                    'feed_id' => $id, 'shape_id' => $shapeId,
                    'points' => json_encode($points, JSON_THROW_ON_ERROR),
                ];
            }
        }

        $out['calendars']['osm'] = [
            'feed_id' => $id, 'service_id' => 'osm', 'days_mask' => 127,
            'start_date' => now()->subYear()->toDateString(), 'end_date' => now()->addYears(5)->toDateString(),
        ];

        return array_map('array_values', $out);
    }

    /** @return list<array{id: string, name: string, lat: float, lon: float}> */
    private function stops(array $members, array $nodes, array $ways): array
    {
        $byRole = ['stop' => [], 'platform' => []];
        foreach ($members as [$type, $ref, $role]) {
            $kind = str_starts_with($role, 'stop') ? 'stop' : (str_starts_with($role, 'platform') ? 'platform' : null);
            if ($kind === null) {
                continue;
            }
            $stop = $this->stop($type, $ref, $nodes, $ways);
            if ($stop !== null && (end($byRole[$kind]) === false || end($byRole[$kind])['id'] !== $stop['id'])) {
                $byRole[$kind][] = $stop;
            }
        }

        return $byRole['stop'] !== [] ? $byRole['stop'] : $byRole['platform'];
    }

    private function stop(string $type, int $ref, array $nodes, array $ways): ?array
    {
        if ($type === 'n' && isset($nodes[$ref])) {
            return ['id' => 'n' . $ref, 'name' => $nodes[$ref][2], 'lon' => round($nodes[$ref][0], 7), 'lat' => round($nodes[$ref][1], 7)];
        }
        if ($type !== 'w' || ! isset($ways[$ref])) {
            return null;
        }

        $points = array_values(array_filter(array_map(static fn (int $n): ?array => $nodes[$n] ?? null, $ways[$ref][0])));
        if ($points === []) {
            return null;
        }

        return [
            'id' => 'w' . $ref,
            'name' => $ways[$ref][1],
            'lon' => round(array_sum(array_column($points, 0)) / count($points), 7),
            'lat' => round(array_sum(array_column($points, 1)) / count($points), 7),
        ];
    }

    /**
     * Chains the route's ways into polylines. Pieces that cannot be joined stay separate,
     * longest first, so the first one is the main line.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private function pieces(array $members, array $nodes, array $ways): array
    {
        $segments = [];
        foreach ($members as [$type, $ref, $role]) {
            if ($type !== 'w' || ! isset($ways[$ref]) || str_starts_with($role, 'platform') || str_starts_with($role, 'stop')) {
                continue;
            }
            $ids = array_values(array_filter($ways[$ref][0], static fn (int $n): bool => isset($nodes[$n])));
            if (count($ids) >= 2) {
                $segments[] = $ids;
            }
        }

        $pieces = [];
        while ($segments !== []) {
            $piece = array_shift($segments);
            do {
                $joined = false;
                foreach ($segments as $key => $candidate) {
                    $head = $piece[0];
                    $tail = $piece[count($piece) - 1];
                    $first = $candidate[0];
                    $last = $candidate[count($candidate) - 1];
                    if ($tail === $first) {
                        $piece = array_merge($piece, array_slice($candidate, 1));
                    } elseif ($tail === $last) {
                        $piece = array_merge($piece, array_slice(array_reverse($candidate), 1));
                    } elseif ($head === $last) {
                        $piece = array_merge(array_slice($candidate, 0, -1), $piece);
                    } elseif ($head === $first) {
                        $piece = array_merge(array_slice(array_reverse($candidate), 0, -1), $piece);
                    } else {
                        continue;
                    }
                    unset($segments[$key]);
                    $joined = true;
                    break;
                }
            } while ($joined);
            $pieces[] = $piece;
        }

        usort($pieces, static fn (array $a, array $b): int => count($b) <=> count($a));

        return array_map(
            fn (array $ids): array => array_map(static fn (int $n): array => [round($nodes[$n][0], 6), round($nodes[$n][1], 6)], $ids),
            $pieces
        );
    }

    /**
     * The extract is cut by bbox, so a route of a neighbouring area shows up with most of its
     * stops missing. Keep it only if most stops exist and lie inside this feed's bbox.
     */
    private function belongsHere(TransitFeed $feed, array $members, array $stops, array $pieces, array $nodes, array $ways): bool
    {
        $expected = 0;
        $found = 0;
        foreach ($members as [$type, $ref, $role]) {
            if (! str_starts_with($role, 'stop') && ! str_starts_with($role, 'platform')) {
                continue;
            }
            $expected++;
            if (($type === 'n' && isset($nodes[$ref])) || ($type === 'w' && isset($ways[$ref]))) {
                $found++;
            }
        }
        if ($expected > 0 && $found / $expected < self::MIN_INSIDE_SHARE) {
            return false;
        }

        $points = $stops !== []
            ? $stops
            : array_map(static fn (array $p): array => ['lon' => $p[0][0], 'lat' => $p[0][1]], $pieces);

        return $this->mostlyInside($feed, $points);
    }

    private function mostlyInside(TransitFeed $feed, array $points): bool
    {
        if ($points === []) {
            return false;
        }
        $inside = 0;
        foreach ($points as $p) {
            if ($this->contains($feed, $p['lon'], $p['lat'])) {
                $inside++;
            }
        }

        return $inside / count($points) >= self::MIN_INSIDE_SHARE;
    }

    /** Uses the county polygon when the feed has one, so border routes land in one county only; falls back to the bbox. */
    private function contains(TransitFeed $feed, float $lon, float $lat): bool
    {
        if ($lat < (float) $feed->min_lat || $lat > (float) $feed->max_lat
            || $lon < (float) $feed->min_lon || $lon > (float) $feed->max_lon) {
            return false;
        }
        if (empty($feed->boundary)) {
            return true;
        }

        $inside = false;
        foreach ($feed->boundary as $ring) {
            for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
                [$xi, $yi] = $ring[$i];
                [$xj, $yj] = $ring[$j];
                if (($yi > $lat) !== ($yj > $lat) && $lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                    $inside = ! $inside;
                }
            }
        }

        return $inside;
    }

    private function color(?string $value): ?string
    {
        $value = ltrim((string) $value, '#');

        return preg_match('/^[0-9a-f]{6}$/i', $value) ? strtoupper($value) : null;
    }
}
