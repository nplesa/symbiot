<?php

namespace App\Support;

/** Reads the points of a stored route geometry (GeoJSON line, feature or feature collection). */
final class GeoJsonLine
{
    /**
     * All valid points, in order: LineString / MultiLineString, also wrapped in a Feature
     * or FeatureCollection. Non-numeric or out-of-range coordinates are skipped.
     *
     * @param  array<string, mixed>|null  $geometry
     * @return list<array{0: float, 1: float, 2: ?float}> [longitude, latitude, elevation or null]
     */
    public static function points(?array $geometry): array
    {
        if (! $geometry) {
            return [];
        }

        return match ($geometry['type'] ?? null) {
            'Feature' => self::points(is_array($geometry['geometry'] ?? null) ? $geometry['geometry'] : null),
            'FeatureCollection' => array_merge([], ...array_map(
                static fn (mixed $feature): array => is_array($feature) ? self::points($feature) : [],
                array_values(is_array($geometry['features'] ?? null) ? $geometry['features'] : [])
            )),
            'LineString' => self::line($geometry['coordinates'] ?? []),
            'MultiLineString' => array_merge([], ...array_map(
                static fn (mixed $line): array => self::line($line),
                array_values(is_array($geometry['coordinates'] ?? null) ? $geometry['coordinates'] : [])
            )),
            default => [],
        };
    }

    /** @return list<array{0: float, 1: float, 2: ?float}> */
    private static function line(mixed $line): array
    {
        if (! is_array($line)) {
            return [];
        }

        $points = [];
        foreach ($line as $point) {
            if (! is_array($point) || ! isset($point[0], $point[1]) || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                continue;
            }

            $lon = (float) $point[0];
            $lat = (float) $point[1];
            if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
                continue;
            }

            $points[] = [$lon, $lat, isset($point[2]) && is_numeric($point[2]) ? (float) $point[2] : null];
        }

        return $points;
    }
}
