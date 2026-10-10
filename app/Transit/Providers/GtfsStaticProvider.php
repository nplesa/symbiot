<?php

namespace App\Transit\Providers;

use App\Transit\Contracts\TransitProvider;
use App\Transit\Models\TransitFeed;
use App\Transit\Services\FeedProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

class GtfsStaticProvider implements TransitProvider
{
    private const CHUNK = 500;

    private const TABLES = [
        'transit_shapes', 'transit_calendar_dates', 'transit_calendars', 'transit_stop_times',
        'transit_trips', 'transit_routes', 'transit_stops', 'transit_agencies',
    ];

    private ?FeedProgress $progress = null;

    private int $totalBytes = 1;

    private int $doneBytes = 0;

    private ?TransitFeed $current = null;

    public function key(): string
    {
        return 'gtfs';
    }

    public function importStatic(TransitFeed $feed, bool $force = false): bool
    {
        $downloadUrls = collect([$feed->static_url, $feed->backup_static_url])
            ->filter(fn (?string $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->values();
        if ($downloadUrls->isEmpty()) {
            throw new RuntimeException("Feed {$feed->slug} has no static GTFS URL.");
        }

        $this->progress = new FeedProgress;
        $this->current = $feed;
        $path = tempnam(sys_get_temp_dir(), 'gtfs');
        $this->progress->report($feed, 'downloading', 1);

        try {
            $downloadErrors = [];
            $downloaded = false;
            foreach ($downloadUrls as $url) {
                try {
                    file_put_contents($path, '');
                    $response = Http::withHeaders(['User-Agent' => 'Symbiot/1.0 GTFS importer'])
                        ->timeout(300)
                        ->retry(2, 500)
                        ->sink($path)
                        ->withOptions(['progress' => function ($total, $bytes) use ($feed): void {
                            if ($total > 0) {
                                $this->progress->report($feed, 'downloading', 1 + 14 * $bytes / $total, null, false);
                            }
                        }])
                        ->get($url);
                    $response->throw();

                    $probe = new ZipArchive;
                    if ($probe->open($path) !== true) {
                        throw new RuntimeException('The GTFS download is not a valid ZIP archive.');
                    }
                    $probe->close();
                    $downloaded = true;
                    break;
                } catch (Throwable $e) {
                    $downloadErrors[] = $url . ': ' . $e->getMessage();
                }
            }
            if (! $downloaded) {
                throw new RuntimeException('All GTFS download URLs failed. ' . implode(' | ', $downloadErrors));
            }

            $hash = hash_file('sha256', $path);
            if (! $force && $feed->import_status === 'imported' && $feed->static_hash === $hash) {
                $feed->forceFill(['last_imported_at' => now()])->save();

                return false;
            }

            $feed->forceFill(['import_status' => 'importing', 'import_error' => null])->save();
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('The GTFS download is not a valid ZIP archive.');
            }

            try {
                foreach (['stops.txt', 'routes.txt', 'trips.txt', 'stop_times.txt'] as $required) {
                    if ($zip->locateName($required) === false) {
                        throw new RuntimeException("GTFS feed is missing {$required}.");
                    }
                }

                $this->measure($zip);
                DB::transaction(function () use ($feed, $path): void {
                    $this->clear($feed);
                    $this->importAll($feed, $path);
                });
            } finally {
                $zip->close();
            }

            $this->progress->report($feed, 'done', 100);
            $feed->forceFill([
                'static_hash' => $hash,
                'import_status' => 'imported',
                'last_imported_at' => now(),
            ])->save();

            return true;
        } catch (Throwable $e) {
            $this->progress->report($feed, 'failed', 0, $e->getMessage());
            $feed->forceFill(['import_status' => 'failed', 'import_error' => $e->getMessage()])->save();
            throw $e;
        } finally {
            @unlink($path);
        }
    }

    private function measure(ZipArchive $zip): void
    {
        $this->totalBytes = 0;
        $this->doneBytes = 0;
        foreach (['agency.txt', 'stops.txt', 'routes.txt', 'trips.txt', 'stop_times.txt', 'calendar.txt', 'calendar_dates.txt', 'shapes.txt'] as $name) {
            $this->totalBytes += (int) ($zip->statName($name)['size'] ?? 0);
        }
        $this->totalBytes = max(1, $this->totalBytes);
    }

    private function clear(TransitFeed $feed): void
    {
        $this->progress->report($feed, 'preparing', 15);
        foreach (self::TABLES as $table) {
            DB::table($table)->where('feed_id', $feed->id)->delete();
        }
    }

    private function importAll(TransitFeed $feed, string $path): void
    {
        $id = $feed->id;

        $this->load($path, 'agency.txt', 'transit_agencies', fn (array $r): array => [
            'feed_id' => $id,
            'agency_id' => $r['agency_id'] ?? '',
            'name' => $r['agency_name'] ?? '',
            'url' => $this->nullable($r['agency_url'] ?? null),
            'timezone' => $this->nullable($r['agency_timezone'] ?? null),
        ]);

        $this->load($path, 'stops.txt', 'transit_stops', function (array $r) use ($id): ?array {
            if (! is_numeric($r['stop_lat'] ?? null) || ! is_numeric($r['stop_lon'] ?? null)) {
                return null;
            }

            return [
                'feed_id' => $id,
                'stop_id' => $r['stop_id'],
                'code' => $this->nullable($r['stop_code'] ?? null),
                'name' => $r['stop_name'] ?? '',
                'lat' => (float) $r['stop_lat'],
                'lon' => (float) $r['stop_lon'],
                'location_type' => (int) ($r['location_type'] ?? 0),
                'parent_station' => $this->nullable($r['parent_station'] ?? null),
                'wheelchair_boarding' => isset($r['wheelchair_boarding']) && $r['wheelchair_boarding'] !== ''
                    ? (int) $r['wheelchair_boarding'] : null,
            ];
        });

        $this->load($path, 'routes.txt', 'transit_routes', fn (array $r): array => [
            'feed_id' => $id,
            'route_id' => $r['route_id'],
            'agency_id' => $this->nullable($r['agency_id'] ?? null),
            'short_name' => $this->nullable($r['route_short_name'] ?? null),
            'long_name' => $this->nullable($r['route_long_name'] ?? null),
            'route_type' => (int) ($r['route_type'] ?? 3),
            'color' => $this->color($r['route_color'] ?? null),
            'text_color' => $this->color($r['route_text_color'] ?? null),
        ]);

        $this->load($path, 'trips.txt', 'transit_trips', fn (array $r): array => [
            'feed_id' => $id,
            'trip_id' => $r['trip_id'],
            'route_id' => $r['route_id'],
            'service_id' => $r['service_id'],
            'headsign' => $this->nullable($r['trip_headsign'] ?? null),
            'direction_id' => isset($r['direction_id']) && $r['direction_id'] !== '' ? (int) $r['direction_id'] : null,
            'shape_id' => $this->nullable($r['shape_id'] ?? null),
        ]);

        $this->load($path, 'stop_times.txt', 'transit_stop_times', fn (array $r): array => [
            'feed_id' => $id,
            'trip_id' => $r['trip_id'],
            'stop_id' => $r['stop_id'],
            'stop_sequence' => (int) $r['stop_sequence'],
            'arrival_seconds' => $this->seconds($r['arrival_time'] ?? null),
            'departure_seconds' => $this->seconds($r['departure_time'] ?? null),
        ]);

        $this->load($path, 'calendar.txt', 'transit_calendars', fn (array $r): array => [
            'feed_id' => $id,
            'service_id' => $r['service_id'],
            'days_mask' => $this->daysMask($r),
            'start_date' => $this->date($r['start_date']),
            'end_date' => $this->date($r['end_date']),
        ]);

        $this->load($path, 'calendar_dates.txt', 'transit_calendar_dates', fn (array $r): array => [
            'feed_id' => $id,
            'service_id' => $r['service_id'],
            'date' => $this->date($r['date']),
            'exception_type' => (int) $r['exception_type'],
        ]);

        $this->importShapes($path, $id);
    }

    private function importShapes(string $path, int $feedId): void
    {
        $shapes = [];
        $this->each($path, 'shapes.txt', function (array $r) use (&$shapes): void {
            $shapes[$r['shape_id']][(int) $r['shape_pt_sequence']] = [
                round((float) $r['shape_pt_lon'], 6),
                round((float) $r['shape_pt_lat'], 6),
            ];
        });

        $rows = [];
        foreach ($shapes as $shapeId => $points) {
            ksort($points);
            $rows[] = [
                'feed_id' => $feedId,
                'shape_id' => (string) $shapeId,
                'points' => json_encode(array_values($points), JSON_THROW_ON_ERROR),
            ];
            if (count($rows) >= 50) {
                DB::table('transit_shapes')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('transit_shapes')->insert($rows);
        }
    }

    private function load(string $path, string $file, string $table, callable $map): void
    {
        $batch = [];
        $this->each($path, $file, function (array $row) use (&$batch, $table, $map): void {
            $mapped = $map($row);
            if ($mapped === null) {
                return;
            }
            $batch[] = $mapped;
            if (count($batch) >= self::CHUNK) {
                DB::table($table)->insertOrIgnore($batch);
                $batch = [];
            }
        });
        if ($batch !== []) {
            DB::table($table)->insertOrIgnore($batch);
        }
    }

    private function each(string $path, string $file, callable $callback): void
    {
        $handle = @fopen('zip://' . $path . '#' . $file, 'r');
        if ($handle === false) {
            return; // optional files such as calendar_dates.txt or shapes.txt
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (! $header) {
                return;
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map('trim', $header);
            $width = count($header);
            $rows = 0;

            while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($line === [null]) {
                    continue;
                }
                $line = array_slice(array_pad($line, $width, ''), 0, $width);
                $callback(array_combine($header, array_map('trim', $line)));
                if ((++$rows % 200) === 0 && $this->current) {
                    $read = (int) ftell($handle);
                    $this->progress->report($this->current, 'importing', 15 + 83 * min(1, ($this->doneBytes + $read) / $this->totalBytes), $file, false);
                }
            }
        } finally {
            $this->doneBytes += (int) ftell($handle);
            fclose($handle);
        }
    }

    private function nullable(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    private function color(?string $value): ?string
    {
        $value = ltrim((string) $value, '#');

        return preg_match('/^[0-9a-f]{6}$/i', $value) ? strtoupper($value) : null;
    }

    private function seconds(?string $time): ?int
    {
        if (! $time || ! preg_match('/^(\d+):(\d{2}):(\d{2})$/', $time, $m)) {
            return null;
        }

        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }

    private function date(string $value): string
    {
        return substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
    }

    private function daysMask(array $row): int
    {
        $mask = 0;
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $bit => $day) {
            if (($row[$day] ?? '0') === '1') {
                $mask |= 1 << $bit;
            }
        }

        return $mask;
    }
}
