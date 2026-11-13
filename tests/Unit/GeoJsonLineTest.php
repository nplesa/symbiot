<?php

namespace Tests\Unit;

use App\Support\GeoJsonLine;
use PHPUnit\Framework\TestCase;

class GeoJsonLineTest extends TestCase
{
    public function test_a_line_string_keeps_valid_points_and_their_elevation(): void
    {
        $points = GeoJsonLine::points([
            'type' => 'LineString',
            'coordinates' => [[26.1, 44.4, 80], [26.2, 44.5], ['x', 44.6], [200, 44.7], [26.3]],
        ]);

        $this->assertSame([[26.1, 44.4, 80.0], [26.2, 44.5, null]], $points);
    }

    public function test_multi_lines_features_and_collections_are_flattened_in_order(): void
    {
        $points = GeoJsonLine::points([
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[1, 2]]]],
                ['type' => 'Feature', 'geometry' => [
                    'type' => 'MultiLineString',
                    'coordinates' => [[[3, 4]], 'not a line', [[5, 6]]],
                ]],
            ],
        ]);

        $this->assertSame([[1.0, 2.0, null], [3.0, 4.0, null], [5.0, 6.0, null]], $points);
    }

    public function test_missing_or_unknown_geometry_has_no_points(): void
    {
        $this->assertSame([], GeoJsonLine::points(null));
        $this->assertSame([], GeoJsonLine::points(['type' => 'Point', 'coordinates' => [1, 2]]));
    }
}
