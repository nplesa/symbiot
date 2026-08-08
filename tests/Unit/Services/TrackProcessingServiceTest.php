<?php

namespace Tests\Unit\Services;

use App\Models\Tracking;
use App\Services\TrackProcessingService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TrackProcessingServiceTest extends TestCase
{
    public function test_empty_collection_returns_zero_distance_and_empty_geojson(): void
    {
        $service = new TrackProcessingService();

        $result = $service->process(collect());

        $this->assertSame(0.0, $result['distance']);

        $this->assertSame([
            'type' => 'LineString',
            'coordinates' => [],
        ], $result['geojson']);
    }

    public function test_single_point_returns_zero_distance(): void
    {
        $service = new TrackProcessingService();

        $points = collect([
            $this->point(45.6486, 25.6061),
        ]);

        $result = $service->process($points);

        $this->assertSame(0.0, $result['distance']);

        $this->assertSame([
            'type' => 'LineString',
            'coordinates' => [
                [25.6061, 45.6486],
            ],
        ], $result['geojson']);
    }

    public function test_two_points_calculate_distance(): void
    {
        $service = new TrackProcessingService();

        $points = collect([
            $this->point(45.6486, 25.6061),
            $this->point(45.6496, 25.6071),
        ]);

        $result = $service->process($points);

        $this->assertGreaterThan(0, $result['distance']);

        $this->assertEqualsWithDelta(
            136.0,
            $result['distance'],
            20.0
        );
    }

    public function test_multiple_points_sum_segment_distances(): void
    {
        $service = new TrackProcessingService();

        $points = collect([
            $this->point(45.6486, 25.6061),
            $this->point(45.6496, 25.6071),
            $this->point(45.6506, 25.6081),
        ]);

        $result = $service->process($points);

        $this->assertGreaterThan(0, $result['distance']);

        $this->assertEqualsWithDelta(
            272.0,
            $result['distance'],
            30.0
        );
    }

    public function test_geojson_uses_longitude_then_latitude(): void
    {
        $service = new TrackProcessingService();

        $points = collect([
            $this->point(45.6486, 25.6061),
            $this->point(46.0000, 26.0000),
        ]);

        $result = $service->process($points);

        $this->assertSame('LineString', $result['geojson']['type']);

        $this->assertSame([
            [25.6061, 45.6486],
            [26.0, 46.0],
        ], $result['geojson']['coordinates']);
    }

    public function test_geojson_preserves_point_order(): void
    {
        $service = new TrackProcessingService();

        $points = collect([
            $this->point(45.0, 25.0),
            $this->point(46.0, 26.0),
            $this->point(47.0, 27.0),
        ]);

        $result = $service->process($points);

        $this->assertSame([
            [25.0, 45.0],
            [26.0, 46.0],
            [27.0, 47.0],
        ], $result['geojson']['coordinates']);
    }

    public function test_points_are_sorted_by_tracked_at_and_id(): void
    {
        $service = new TrackProcessingService();

        $first = $this->point(45.0, 25.0);
        $first->id = 1;
        $first->tracked_at = now()->subMinute();

        $sameTimeLaterId = $this->point(46.0, 26.0);
        $sameTimeLaterId->id = 3;
        $sameTimeLaterId->tracked_at = $first->tracked_at->copy();

        $sameTimeEarlierId = $this->point(45.5, 25.5);
        $sameTimeEarlierId->id = 2;
        $sameTimeEarlierId->tracked_at = $first->tracked_at->copy();

        $result = $service->process(collect([
            $sameTimeLaterId,
            $first,
            $sameTimeEarlierId,
        ]));

        $this->assertSame([
            [25.0, 45.0],
            [25.5, 45.5],
            [26.0, 46.0],
        ], $result['geojson']['coordinates']);
    }

    public function test_distance_is_finite_for_normal_coordinates(): void
    {
        $service = new TrackProcessingService();

        $points = collect([
            $this->point(0.0, 0.0),
            $this->point(0.0, 180.0),
        ]);

        $result = $service->process($points);

        $this->assertTrue(is_finite($result['distance']));
        $this->assertEqualsWithDelta(20003900.0, $result['distance'], 1000.0);
    }

    private function point(float $latitude, float $longitude): Tracking
    {
        $point = new Tracking();

        $point->latitude = $latitude;
        $point->longitude = $longitude;

        return $point;
    }
}
