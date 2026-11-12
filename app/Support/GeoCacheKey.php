<?php

namespace App\Support;

/**
 * Cache keys for "what is around (lat, lon) within radius" lookups. The position is
 * snapped to a grid of 10% of the radius (at least 50 m), so nearby requests share an
 * entry while a small radius never reuses the result of a point far outside it.
 */
final class GeoCacheKey
{
    private const METERS_PER_DEGREE = 111320;

    private const MIN_STEP_METERS = 50;

    public static function around(string $prefix, float $lat, float $lon, int $radius): string
    {
        $stepMeters = max(self::MIN_STEP_METERS, $radius * 0.1);

        $latStep = $stepMeters / self::METERS_PER_DEGREE;
        $latCell = (int) floor($lat / $latStep);

        // Longitude cells use the cell's own latitude, so every point in it gets the same width.
        $cellLat = ($latCell + 0.5) * $latStep;
        $lonStep = $stepMeters / (self::METERS_PER_DEGREE * max(0.01, cos(deg2rad($cellLat))));
        $lonCell = (int) floor($lon / $lonStep);

        return sprintf('%s:%d:%d:%d', $prefix, $latCell, $lonCell, $radius);
    }
}
