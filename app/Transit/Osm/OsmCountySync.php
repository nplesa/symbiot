<?php

namespace App\Transit\Osm;

use App\Transit\Models\TransitFeed;

/** Creates one OSM-backed feed per Romanian county, with its bounding box taken from the boundary geometry. */
class OsmCountySync
{
    public function __construct(private readonly OsmiumTools $osmium = new OsmiumTools) {}

    public function syncFromPbf(): int
    {
        $opl = tempnam(sys_get_temp_dir(), 'osmc');
        try {
            $this->osmium->countiesOpl($opl);

            return $this->syncFromOpl($opl);
        } finally {
            @unlink($opl);
        }
    }

    public function syncFromOpl(string $path): int
    {
        $nodes = [];
        $ways = [];
        $counties = [];
        foreach (OplReader::read($path) as $object) {
            if ($object['type'] === 'n') {
                $nodes[$object['id']] = [$object['lon'], $object['lat']];
            } elseif ($object['type'] === 'w') {
                $ways[$object['id']] = $object['nodes'] ?? [];
            } elseif (($object['tags']['boundary'] ?? '') === 'administrative'
                && ($object['tags']['admin_level'] ?? '') === '4'
                && str_starts_with($object['tags']['ISO3166-2'] ?? '', 'RO-')) {
                $counties[] = $object;
            }
        }

        $count = 0;
        foreach ($counties as $county) {
            $box = $this->bbox($county['members'] ?? [], $nodes, $ways);
            if ($box === null) {
                continue;
            }

            $iso = strtolower($county['tags']['ISO3166-2']);
            $name = $county['tags']['name'] ?? $county['tags']['ISO3166-2'];
            $feed = TransitFeed::query()->firstOrNew(['slug' => 'osm-' . $iso]);
            $feed->fill([
                'name' => 'OpenStreetMap – ' . $name,
                'country_code' => 'RO',
                'city' => $name,
                'provider' => 'osm',
                'source_reference' => 'osm-relation-' . $county['id'],
                'license' => 'ODbL',
                'active' => true,
                'min_lat' => $box[1], 'min_lon' => $box[0], 'max_lat' => $box[3], 'max_lon' => $box[2],
                'boundary' => $this->rings($county['members'] ?? [], $nodes, $ways) ?: null,
            ]);
            if (! $feed->exists) {
                $feed->import_status = 'pending';
            }
            $feed->save();
            $count++;
        }

        return $count;
    }

    /**
     * Joins the outer ways of the boundary into closed rings and thins them (~50 m), enough for border decisions.
     *
     * @return list<list<array{0: float, 1: float}>> rings of [lon, lat]
     */
    private function rings(array $members, array $nodes, array $ways): array
    {
        $segments = [];
        foreach ($members as [$type, $ref, $role]) {
            if ($type === 'w' && in_array($role, ['outer', ''], true) && count($ways[$ref] ?? []) > 1) {
                $segments[] = $ways[$ref];
            }
        }

        $rings = [];
        while ($segments !== []) {
            $ring = array_shift($segments);
            while ($ring[0] !== end($ring)) {
                $joined = false;
                foreach ($segments as $i => $segment) {
                    $tail = end($ring);
                    if ($segment[0] === $tail) {
                        $ring = array_merge($ring, array_slice($segment, 1));
                    } elseif (end($segment) === $tail) {
                        $ring = array_merge($ring, array_slice(array_reverse($segment), 1));
                    } elseif (end($segment) === $ring[0]) {
                        $ring = array_merge(array_slice($segment, 0, -1), $ring);
                    } elseif ($segment[0] === $ring[0]) {
                        $ring = array_merge(array_slice(array_reverse($segment), 0, -1), $ring);
                    } else {
                        continue;
                    }
                    unset($segments[$i]);
                    $joined = true;
                    break;
                }
                if (! $joined) {
                    break;
                }
            }

            $points = [];
            $last = null;
            foreach ($ring as $nodeId) {
                if (! isset($nodes[$nodeId])) {
                    continue;
                }
                $point = [round($nodes[$nodeId][0], 4), round($nodes[$nodeId][1], 4)];
                if ($last === null || abs($point[0] - $last[0]) >= 0.0005 || abs($point[1] - $last[1]) >= 0.0005) {
                    $points[] = $last = $point;
                }
            }
            if (count($points) >= 3) {
                $rings[] = $points;
            }
        }

        return $rings;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null minLon, minLat, maxLon, maxLat */
    private function bbox(array $members, array $nodes, array $ways): ?array
    {
        $box = null;
        foreach ($members as [$type, $ref, $role]) {
            if ($type !== 'w' || ! in_array($role, ['outer', ''], true)) {
                continue;
            }
            foreach ($ways[$ref] ?? [] as $nodeId) {
                if (! isset($nodes[$nodeId])) {
                    continue;
                }
                [$lon, $lat] = $nodes[$nodeId];
                $box = $box === null
                    ? [$lon, $lat, $lon, $lat]
                    : [min($box[0], $lon), min($box[1], $lat), max($box[2], $lon), max($box[3], $lat)];
            }
        }

        return $box;
    }
}
