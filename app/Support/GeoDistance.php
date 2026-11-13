<?php

namespace App\Support;

final class GeoDistance
{
    /** Earth radius used across the app, in metres. */
    public const EARTH_RADIUS = 6371000.0;

    /**
     * Great-circle distance in metres (haversine).
     *
     * @param  float  $radius  Earth radius in metres; route imports use the IUGG mean radius (6 371 008.8 m)
     */
    public static function meters(float $lat1, float $lon1, float $lat2, float $lon2, float $radius = self::EARTH_RADIUS): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $radius * 2 * asin(min(1.0, sqrt($a)));
    }
}
