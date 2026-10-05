<?php

namespace App\Transit\Services;

use App\Transit\Models\TransitFeed;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirrors the public MobilityData catalog into transit_feeds so feeds can be
 * discovered locally by location. Feeds are only registered here; their data
 * is imported later, on demand.
 */
class FeedCatalogSync
{
    /** @return array{feeds: int, realtime: int} */
    public function sync(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'mdbcatalog');

        try {
            Http::withHeaders(['User-Agent' => 'Symbiot/1.0 GTFS catalog'])
                ->timeout(120)
                ->retry(2, 500)
                ->sink($path)
                ->get(config('transit.catalog_url'))
                ->throw();

            return $this->importCsv($path);
        } finally {
            @unlink($path);
        }
    }

    /** @return array{feeds: int, realtime: int} */
    public function importCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Cannot read the feed catalog.');
        }

        $feeds = [];
        $realtime = [];

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (! $header) {
                throw new RuntimeException('The feed catalog is empty.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            if (! in_array('data_type', $header, true)) {
                throw new RuntimeException('The feed catalog has an unexpected format.');
            }
            $width = count($header);

            while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($line === [null]) {
                    continue;
                }
                $row = array_combine($header, array_slice(array_pad($line, $width, ''), 0, $width));
                if (($row['status'] ?? '') !== 'active' || ($row['urls.authentication_type'] ?? '0') !== '0') {
                    continue;
                }

                if ($row['data_type'] === 'gtfs') {
                    $feed = $this->staticFeed($row);
                    if ($feed !== null) {
                        $feeds[$row['id']] = $feed;
                    }
                } elseif ($row['data_type'] === 'gtfs_rt' && $row['urls.direct_download'] !== '') {
                    foreach (explode('|', $row['static_reference']) as $reference) {
                        foreach (explode('|', $row['entity_type']) as $entity) {
                            $realtime[trim($reference)][$entity] = $row['urls.direct_download'];
                        }
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        if ($feeds === []) {
            throw new RuntimeException('The feed catalog contains no usable GTFS feeds.');
        }

        $now = now();
        $existingByReference = TransitFeed::query()->where('provider', 'gtfs')->whereNotNull('source_reference')->pluck('id', 'source_reference');
        $existingByUrl = TransitFeed::query()->where('provider', 'gtfs')->whereNull('source_reference')->pluck('id', 'static_url');
        $count = 0;

        foreach ($feeds as $reference => $attributes) {
            $rt = $realtime[$reference] ?? [];
            $attributes += [
                'vehicle_positions_url' => $rt['vp'] ?? null,
                'trip_updates_url' => $rt['tu'] ?? null,
                'alerts_url' => $rt['sa'] ?? null,
                'catalog_synced_at' => $now,
            ];

            $id = $existingByReference[$reference] ?? $existingByUrl[$attributes['static_url']] ?? null;
            if ($id !== null) {
                TransitFeed::query()->whereKey($id)->update($attributes + ['source_reference' => $reference]);
            } else {
                TransitFeed::create($attributes + [
                    'slug' => $this->slug($reference),
                    'source_reference' => $reference,
                    'import_status' => 'pending',
                ]);
            }
            $count++;
        }

        // Feeds that left the catalog keep their data but stop being auto-imported.
        $removed = $existingByReference->except(array_keys($feeds))->values();
        foreach ($removed->chunk(500) as $ids) {
            TransitFeed::query()->whereKey($ids->all())->update(['active' => false]);
        }

        return ['feeds' => $count, 'realtime' => count($realtime)];
    }

    /** @return array<string, mixed>|null */
    private function staticFeed(array $row): ?array
    {
        $url = $row['urls.direct_download'];
        $box = [
            $row['location.bounding_box.minimum_latitude'],
            $row['location.bounding_box.maximum_latitude'],
            $row['location.bounding_box.minimum_longitude'],
            $row['location.bounding_box.maximum_longitude'],
        ];
        if ($url === '' || in_array('', $box, true) || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }
        $box = array_map('floatval', $box);
        if (abs($box[0]) > 90 || abs($box[1]) > 90 || abs($box[2]) > 180 || abs($box[3]) > 180
            || $box[0] > $box[1] || $box[2] > $box[3]) {
            return null;
        }

        return [
            'name' => ($row['provider'] !== '' ? $row['provider'] : ($row['name'] ?: $row['id'])),
            'country_code' => $row['location.country_code'] ?: null,
            'city' => $row['location.municipality'] ?: ($row['location.subdivision_name'] ?: null),
            'static_url' => $url,
            'license' => $row['urls.license'] ?: null,
            'is_official' => strcasecmp($row['is_official'], 'true') === 0,
            'min_lat' => $box[0],
            'max_lat' => $box[1],
            'min_lon' => $box[2],
            'max_lon' => $box[3],
            'active' => true,
        ];
    }

    private function slug(string $reference): string
    {
        return substr(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $reference)), 0, 250);
    }
}
