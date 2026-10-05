<?php

namespace App\Transit\Osm;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Thin wrapper over the osmium CLI used on the local Romania extract. */
class OsmiumTools
{
    public const ROUTE_TYPES = 'bus,trolleybus,tram,share_taxi,minibus';

    public function pbf(): string
    {
        return rtrim((string) config('osm.romania.data_path'), '\\/') . DIRECTORY_SEPARATOR . 'romania.osm.pbf';
    }

    public function available(): bool
    {
        return is_file($this->pbf());
    }

    /** Public transport route relations only; rebuilt when the source extract is newer. */
    public function routesPbf(): string
    {
        $target = dirname($this->pbf()) . DIRECTORY_SEPARATOR . 'transit-routes.osm.pbf';
        if (! is_file($target) || filemtime($target) < filemtime($this->pbf())) {
            $this->run(['tags-filter', $this->pbf(), 'r/route=' . self::ROUTE_TYPES, '-o', $target, '--overwrite']);
        }

        return $target;
    }

    /** Boundaries with admin_level=4 (counties) as OPL text. */
    public function countiesOpl(string $target): void
    {
        $filtered = $target . '.pbf';
        $this->run(['tags-filter', $this->pbf(), 'r/admin_level=4', '-o', $filtered, '--overwrite']);
        $this->run(['cat', $filtered, '-f', 'opl', '-o', $target, '--overwrite']);
        @unlink($filtered);
    }

    public function extractOpl(string $source, float $minLon, float $minLat, float $maxLon, float $maxLat, string $target): void
    {
        $this->run([
            'extract', '-b', sprintf('%.6f,%.6f,%.6f,%.6f', $minLon, $minLat, $maxLon, $maxLat),
            '-s', 'complete_ways', $source, '-f', 'opl', '-o', $target, '--overwrite',
        ]);
    }

    private function run(array $arguments): void
    {
        $process = new Process(array_merge([(string) config('osm.romania.osmium_binary')], $arguments));
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('osmium failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }
}
