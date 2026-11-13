<?php

namespace Tests\Unit;

use App\Support\GeoDistance;
use PHPUnit\Framework\TestCase;

class GeoDistanceTest extends TestCase
{
    public function test_one_degree_of_latitude_is_about_111_km(): void
    {
        $this->assertEqualsWithDelta(111195, GeoDistance::meters(45, 25, 46, 25), 1);
    }

    public function test_the_same_point_is_zero_metres_away(): void
    {
        $this->assertSame(0.0, GeoDistance::meters(44.4268, 26.1025, 44.4268, 26.1025));
    }

    public function test_a_custom_earth_radius_scales_the_distance(): void
    {
        $default = GeoDistance::meters(44.4268, 26.1025, 46.7712, 23.6236);
        $mean = GeoDistance::meters(44.4268, 26.1025, 46.7712, 23.6236, 6371008.8);

        $this->assertEqualsWithDelta($default * 6371008.8 / 6371000, $mean, 0.001);
    }
}
