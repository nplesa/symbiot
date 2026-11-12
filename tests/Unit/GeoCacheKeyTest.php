<?php

namespace Tests\Unit;

use App\Support\GeoCacheKey;
use PHPUnit\Framework\TestCase;

class GeoCacheKeyTest extends TestCase
{
    /** ~$meters north of (45.65, 25.60). */
    private function north(float $meters): float
    {
        return 45.65 + $meters / 111320;
    }

    public function test_small_radius_does_not_share_results_between_distant_points(): void
    {
        $this->assertNotSame(
            GeoCacheKey::around('k', 45.65, 25.60, 300),
            GeoCacheKey::around('k', $this->north(400), 25.60, 300)
        );
    }

    public function test_large_radius_shares_results_between_nearby_points(): void
    {
        // The grid step is 10% of the radius (500 m here); stay inside one cell.
        $this->assertSame(
            GeoCacheKey::around('k', 45.65010, 25.60010, 5000),
            GeoCacheKey::around('k', 45.65010 + 100 / 111320, 25.60010, 5000)
        );
    }

    public function test_different_radii_never_share_an_entry(): void
    {
        $this->assertNotSame(
            GeoCacheKey::around('k', 45.65, 25.60, 100),
            GeoCacheKey::around('k', 45.65, 25.60, 400)
        );
    }

    public function test_grid_step_never_drops_below_fifty_meters(): void
    {
        // 10% of 100 m would be 10 m; the minimum 50 m step keeps points 20 m apart together.
        $this->assertSame(
            GeoCacheKey::around('k', 45.650010, 25.60, 100),
            GeoCacheKey::around('k', 45.650010 + 20 / 111320, 25.60, 100)
        );
    }
}
