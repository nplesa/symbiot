<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class RomaniaOsmPoiSyncService
{
    private const REGION = 'romania';

    public function __construct(private readonly PoiCatalog $catalog) {}

    /** @return array{status: string, sequence: int, records: int} */
    public function sync(bool $full = false): array
    {
        $lock = Cache::lock('osm-romania-poi-sync', 7200);
        if (! $lock->get()) {
            throw new RuntimeException('A Romania OSM synchronization is already running.');
        }

        try {
            return $this->synchronize($full);
        } finally {
            $lock->release();
        }
    }

    /** @return array{status: string, sequence: int, records: int} */
    private function synchronize(bool $full): array
    {
        $directory = config('osm.romania.data_path');
        File::ensureDirectoryExists($directory);

        $pbfPath = $directory . DIRECTORY_SEPARATOR . 'romania.osm.pbf';
        if ($full || ! File::exists($pbfPath)) {
            $this->download(config('osm.romania.extract_url'), $pbfPath . '.download');
            $this->replaceFile($pbfPath . '.download', $pbfPath);
        }

        $sequence = $this->pbfSequence($pbfPath);
        $latestState = $this->readState(rtrim(config('osm.romania.updates_url'), '/') . '/state.txt');
        $latestSequence = $latestState['sequence'];
        if ($sequence > $latestSequence) {
            throw new RuntimeException('The installed Romania extract is newer than the Geofabrik update state.');
        }

        $snapshotAt = Carbon::parse($latestState['timestamp'])->utc()->toDateTimeString();
        while ($sequence < $latestSequence) {
            $nextSequence = $sequence + 1;
            $relativePath = $this->sequencePath($nextSequence);
            $updatesUrl = rtrim(config('osm.romania.updates_url'), '/');
            $changePath = $directory . DIRECTORY_SEPARATOR . $nextSequence . '.osc.gz';
            $statePath = $directory . DIRECTORY_SEPARATOR . $nextSequence . '.state.txt';
            $this->download($updatesUrl . '/' . $relativePath . '.osc.gz', $changePath);

            try {
                $state = $this->readState($updatesUrl . '/' . $relativePath . '.state.txt');
                if ($state['sequence'] !== $nextSequence) {
                    throw new RuntimeException('The downloaded Geofabrik change state has an unexpected sequence number.');
                }

                $updatedPbfPath = $pbfPath . '.updated';
                $this->runOsmium([
                    'apply-changes',
                    '--output=' . $updatedPbfPath,
                    '--output-header=osmosis_replication_sequence_number=' . $nextSequence,
                    '--output-header=osmosis_replication_timestamp=' . $state['timestamp'],
                    $pbfPath,
                    $changePath,
                ]);
                $this->replaceFile($updatedPbfPath, $pbfPath);
                $sequence = $nextSequence;
                $snapshotAt = Carbon::parse($state['timestamp'])->utc()->toDateTimeString();
            } finally {
                File::delete([$changePath, $statePath, $pbfPath . '.updated']);
            }
        }

        $previousImport = DB::table('osm_import_states')->where('region', self::REGION)->first();
        if ($previousImport !== null && (int) $previousImport->replication_sequence === $sequence) {
            DB::table('osm_import_states')
                ->where('region', self::REGION)
                ->update([
                    'snapshot_at' => $snapshotAt,
                    'last_synced_at' => now(),
                ]);

            return ['status' => 'unchanged', 'sequence' => $sequence, 'records' => (int) $previousImport->records_count];
        }

        return $this->importSnapshot($pbfPath, $sequence, $snapshotAt);
    }

    /** @return array{status: string, sequence: int, records: int} */
    private function importSnapshot(string $pbfPath, int $sequence, ?string $snapshotAt): array
    {
        $runId = (string) Str::uuid();
        $directory = dirname($pbfPath);
        $expressionsPath = $directory . DIRECTORY_SEPARATOR . $runId . '.filters.txt';
        $filteredPath = $directory . DIRECTORY_SEPARATOR . $runId . '.osm.pbf';
        $geoJsonPath = $directory . DIRECTORY_SEPARATOR . $runId . '.geojsonseq';
        $batch = [];
        $recordCount = 0;

        try {
            $expressions = $this->filterExpressions();
            if ($expressions === []) {
                throw new RuntimeException('No OSM POI filters are configured for the Romania import.');
            }
            File::put($expressionsPath, implode(PHP_EOL, $expressions) . PHP_EOL);

            $this->runOsmium(['tags-filter', '--expressions=' . $expressionsPath, $pbfPath, '-o', $filteredPath]);
            $this->runOsmium(['export', '--add-unique-id=type_id', '-f', 'geojsonseq', '-o', $geoJsonPath, $filteredPath]);

            $stream = fopen($geoJsonPath, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Osmium did not produce a readable GeoJSON sequence file.');
            }

            try {
                while (($line = fgets($stream)) !== false) {
                    $feature = json_decode(ltrim(trim($line), "\x1e"), true, flags: JSON_THROW_ON_ERROR);
                    $row = $this->featureToRow($feature, $runId);
                    if ($row === null) {
                        continue;
                    }

                    $batch[] = $row;
                    $recordCount++;
                    if (count($batch) >= 500) {
                        DB::table('osm_transport_point_staging')->insert($batch);
                        $batch = [];
                    }
                }
            } finally {
                fclose($stream);
            }

            if ($batch !== []) {
                DB::table('osm_transport_point_staging')->insert($batch);
            }
            if ($recordCount === 0) {
                throw new RuntimeException('The Romania OSM import produced no matching POIs; the active dataset was left unchanged.');
            }

            $syncedAt = now();
            DB::transaction(function () use ($runId, $sequence, $snapshotAt, $recordCount, $syncedAt): void {
                DB::table('transport_points')->where('source', 'openstreetmap')->delete();

                $stagedRows = DB::table('osm_transport_point_staging')
                    ->where('run_id', $runId)
                    ->select(['name', 'type', 'osm_type', 'osm_id', 'tags', 'lat', 'lon', 'imported_at'])
                    ->selectRaw('? as source', ['openstreetmap'])
                    ->selectRaw('? as created_at', [$syncedAt])
                    ->selectRaw('? as updated_at', [$syncedAt]);
                DB::table('transport_points')->insertUsing(
                    ['name', 'type', 'osm_type', 'osm_id', 'tags', 'lat', 'lon', 'imported_at', 'source', 'created_at', 'updated_at'],
                    $stagedRows
                );

                DB::table('osm_import_states')->updateOrInsert(
                    ['region' => self::REGION],
                    [
                        'replication_sequence' => $sequence,
                        'snapshot_at' => $snapshotAt,
                        'records_count' => $recordCount,
                        'last_synced_at' => $syncedAt,
                    ]
                );
            });

            return ['status' => 'imported', 'sequence' => $sequence, 'records' => $recordCount];
        } finally {
            DB::table('osm_transport_point_staging')->where('run_id', $runId)->delete();
            File::delete([$expressionsPath, $filteredPath, $geoJsonPath]);
        }
    }

    /** @param array<string, mixed> $feature
     * @return array<string, mixed>|null
     */
    private function featureToRow(array $feature, string $runId): ?array
    {
        $properties = $feature['properties'] ?? [];
        $tags = [];
        foreach ($properties as $key => $value) {
            if ($key !== '@id' && is_scalar($value)) {
                $tags[$key] = (string) $value;
            }
        }

        $type = $this->catalog->osmType($tags);
        $identity = $this->osmIdentity($feature['id'] ?? $properties['@id'] ?? null);
        $center = $this->geometryCenter($feature['geometry'] ?? null);
        if ($type === null || $identity === null || $center === null) {
            return null;
        }

        [$osmType, $osmId] = $identity;

        return [
            'run_id' => $runId,
            'osm_type' => $osmType,
            'osm_id' => $osmId,
            'name' => $tags['name'] ?? $type,
            'type' => $type,
            'lat' => $center[1],
            'lon' => $center[0],
            'tags' => json_encode($tags, JSON_THROW_ON_ERROR),
            'imported_at' => now(),
        ];
    }

    /** @return array{0: string, 1: string}|null */
    private function osmIdentity(mixed $id): ?array
    {
        if (! is_string($id) && ! is_int($id)) {
            return null;
        }
        $id = (string) $id;
        if (preg_match('/^([nwar])(-?\d+)$/', $id, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }
        if (preg_match('/^(node|way|relation)\/(-?\d+)$/', $id, $matches) === 1) {
            return [match ($matches[1]) {
                'node' => 'n',
                'way' => 'w',
                default => 'r',
            }, $matches[2]];
        }

        return null;
    }

    /** @param array<string, mixed>|null $geometry
     * @return array{0: float, 1: float}|null
     */
    private function geometryCenter(?array $geometry): ?array
    {
        if ($geometry === null || ! isset($geometry['coordinates'])) {
            return null;
        }
        $coordinates = $geometry['coordinates'];
        $type = $geometry['type'] ?? '';

        if ($type === 'Point' && is_array($coordinates) && count($coordinates) >= 2) {
            return [(float) $coordinates[0], (float) $coordinates[1]];
        }

        $points = match ($type) {
            'LineString' => $coordinates,
            'Polygon' => $coordinates[0] ?? [],
            'MultiPolygon' => $coordinates[0][0] ?? [],
            default => [],
        };
        if (! is_array($points) || $points === []) {
            return null;
        }

        if (in_array($type, ['Polygon', 'MultiPolygon'], true)) {
            return $this->polygonCenter($points);
        }

        $longitude = 0.0;
        $latitude = 0.0;
        $count = 0;
        foreach ($points as $point) {
            if (! is_array($point) || count($point) < 2) {
                continue;
            }
            $longitude += (float) $point[0];
            $latitude += (float) $point[1];
            $count++;
        }

        return $count > 0 ? [$longitude / $count, $latitude / $count] : null;
    }

    /** @param list<array{0: float|int, 1: float|int}> $ring
     * @return array{0: float, 1: float}|null
     */
    private function polygonCenter(array $ring): ?array
    {
        $areaFactor = 0.0;
        $longitude = 0.0;
        $latitude = 0.0;
        $pointCount = count($ring);
        if ($pointCount < 3) {
            return null;
        }

        for ($index = 0; $index < $pointCount; $index++) {
            $current = $ring[$index];
            $next = $ring[($index + 1) % $pointCount];
            if (! is_array($current) || ! is_array($next) || count($current) < 2 || count($next) < 2) {
                continue;
            }
            $factor = (float) $current[0] * (float) $next[1] - (float) $next[0] * (float) $current[1];
            $areaFactor += $factor;
            $longitude += ((float) $current[0] + (float) $next[0]) * $factor;
            $latitude += ((float) $current[1] + (float) $next[1]) * $factor;
        }
        if (abs($areaFactor) < 1.0e-12) {
            return null;
        }

        return [$longitude / (3 * $areaFactor), $latitude / (3 * $areaFactor)];
    }

    /** @return list<string> */
    private function filterExpressions(): array
    {
        $expressions = [];
        foreach ($this->catalog->navigation() as $category) {
            foreach ($category['osm'] as $selector) {
                if ($selector === []) {
                    continue;
                }
                $key = array_key_first($selector);
                $value = $selector[$key];
                if ($value === null) {
                    $expressions[] = 'nwr/' . $key;

                    continue;
                }
                $expressions[] = 'nwr/' . $key . '=' . str_replace('|', ',', $value);
            }
        }

        return array_values(array_unique($expressions));
    }

    /** @return array{sequence: int, timestamp: string} */
    private function readState(string $url): array
    {
        $response = Http::timeout(60)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Could not read Geofabrik replication state (HTTP ' . $response->status() . ').');
        }

        $contents = str_replace(['\\:', "\r"], [':', ''], $response->body());
        if (preg_match('/^sequenceNumber=(\d+)$/m', $contents, $sequenceMatch) !== 1
            || preg_match('/^timestamp=(\S+)$/m', $contents, $timestampMatch) !== 1) {
            throw new RuntimeException('Geofabrik returned a malformed replication state file.');
        }

        return ['sequence' => (int) $sequenceMatch[1], 'timestamp' => $timestampMatch[1]];
    }

    private function sequencePath(int $sequence): string
    {
        return sprintf('%03d/%03d/%03d', intdiv($sequence, 1_000_000), intdiv($sequence, 1_000) % 1_000, $sequence % 1_000);
    }

    private function pbfSequence(string $path): int
    {
        $sequence = $this->runOsmium([
            'fileinfo',
            '--get=header.option.osmosis_replication_sequence_number',
            $path,
        ]);
        if (preg_match('/^\s*(\d+)\s*$/', $sequence, $matches) !== 1) {
            throw new RuntimeException('The Romania PBF does not contain a Geofabrik replication sequence number.');
        }

        return (int) $matches[1];
    }

    private function download(string $url, string $destination): void
    {
        File::delete($destination);
        $response = Http::timeout(900)->withOptions(['sink' => $destination])->get($url);
        if (! $response->successful() || ! File::exists($destination) || File::size($destination) === 0) {
            File::delete($destination);
            throw new RuntimeException('OSM download failed with HTTP ' . $response->status() . ': ' . $url);
        }
    }

    private function replaceFile(string $newPath, string $path): void
    {
        $backupPath = $path . '.previous';
        File::delete($backupPath);
        if (File::exists($path) && ! File::move($path, $backupPath)) {
            throw new RuntimeException('Could not preserve the previous Romania OSM extract before promotion.');
        }
        if (! File::move($newPath, $path)) {
            if (File::exists($backupPath)) {
                File::move($backupPath, $path);
            }
            throw new RuntimeException('Could not promote the downloaded or updated Romania OSM extract.');
        }
        File::delete($backupPath);
    }

    /** @param list<string> $arguments */
    private function runOsmium(array $arguments): string
    {
        $process = new Process(array_merge([config('osm.romania.osmium_binary')], $arguments));
        $process->setTimeout(7200);
        $process->run();
        if (! $process->isSuccessful()) {
            $details = trim($process->getErrorOutput());
            throw new RuntimeException(
                'Osmium command failed (' . implode(' ', $arguments) . '): '
                . ($details !== '' ? $details : trim($process->getOutput()))
            );
        }

        return trim($process->getOutput());
    }
}
