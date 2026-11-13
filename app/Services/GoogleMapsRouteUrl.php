<?php

namespace App\Services;

use App\Support\GeoJsonLine;
use App\Models\Route;
use RuntimeException;

class GoogleMapsRouteUrl
{
    private const MAX_WAYPOINTS = 23;

    public function forRoute(Route $route): string
    {
        $coordinates = array_map(
            static fn (array $point): array => [$point[0], $point[1]],
            GeoJsonLine::points($route->geometry)
        );

        if (count($coordinates) < 2) {
            throw new RuntimeException('Traseul nu conține suficiente puncte pentru navigare.');
        }

        $points = $this->sampleCoordinates($coordinates);
        $origin = $this->formatPoint($points[0]);
        $destination = $this->formatPoint($points[array_key_last($points)]);
        $waypoints = array_slice($points, 1, -1);

        $query = [
            'api' => '1',
            'origin' => $origin,
            'destination' => $destination,
            'travelmode' => 'walking',
        ];

        if ($waypoints !== []) {
            $query['waypoints'] = implode('|', array_map(
                fn (array $point): string => $this->formatPoint($point),
                $waypoints
            ));
        }

        return 'https://www.google.com/maps/dir/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param list<array{0: float, 1: float}> $coordinates */
    private function sampleCoordinates(array $coordinates): array
    {
        if (count($coordinates) <= self::MAX_WAYPOINTS + 2) {
            return $coordinates;
        }

        $lastIndex = count($coordinates) - 1;
        $points = [$coordinates[0]];

        for ($index = 1; $index <= self::MAX_WAYPOINTS; $index++) {
            $sourceIndex = (int) round($index * $lastIndex / (self::MAX_WAYPOINTS + 1));
            $points[] = $coordinates[$sourceIndex];
        }

        $points[] = $coordinates[$lastIndex];

        return $points;
    }

    /** @param array{0: float, 1: float} $point */
    private function formatPoint(array $point): string
    {
        return sprintf('%.7F,%.7F', $point[1], $point[0]);
    }
}
