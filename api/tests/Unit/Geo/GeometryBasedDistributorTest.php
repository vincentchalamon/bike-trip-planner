<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Geo\GeometryBasedDistributor;
use App\Geo\HaversineDistance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeometryBasedDistributorTest extends TestCase
{
    private GeometryBasedDistributor $distributor;

    #[\Override]
    protected function setUp(): void
    {
        $this->distributor = new GeometryBasedDistributor(new HaversineDistance());
    }

    #[Test]
    public function distributeByEndpointAssignsToClosestEndpoint(): void
    {
        $stages = [
            new Stage('trip-1', 1, 80.0, 500.0, new Coordinate(45.0, 5.0), new Coordinate(45.5, 5.5)),
            new Stage('trip-1', 2, 80.0, 500.0, new Coordinate(45.5, 5.5), new Coordinate(46.0, 6.0)),
        ];

        $items = [
            ['lat' => 45.4, 'lon' => 5.4],  // Near stage 1 end
            ['lat' => 45.9, 'lon' => 5.9],  // Near stage 2 end
        ];

        $result = $this->distributor->distributeByEndpoint($items, $stages);

        $this->assertCount(1, $result[0]);
        $this->assertCount(1, $result[1]);
        $this->assertEqualsWithDelta(45.4, $result[0][0]['lat'], 0.001);
        $this->assertEqualsWithDelta(45.9, $result[1][0]['lat'], 0.001);
    }

    #[Test]
    public function distributeByEndpointWithEmptyItems(): void
    {
        $stages = [
            new Stage('trip-1', 1, 80.0, 500.0, new Coordinate(45.0, 5.0), new Coordinate(45.5, 5.5)),
        ];

        $result = $this->distributor->distributeByEndpoint([], $stages);

        $this->assertCount(0, $result[0]);
    }

    #[Test]
    public function distributeByGeometryUsesAllGeometryPoints(): void
    {
        $stages = [
            new Stage(
                'trip-1',
                1,
                80.0,
                500.0,
                new Coordinate(45.0, 5.0),
                new Coordinate(46.0, 6.0),
                geometry: [
                    new Coordinate(45.0, 5.0),
                    new Coordinate(45.5, 5.5),
                    new Coordinate(46.0, 6.0),
                ],
            ),
            new Stage(
                'trip-1',
                2,
                80.0,
                500.0,
                new Coordinate(46.0, 6.0),
                new Coordinate(47.0, 7.0),
                geometry: [
                    new Coordinate(46.0, 6.0),
                    new Coordinate(46.5, 6.5),
                    new Coordinate(47.0, 7.0),
                ],
            ),
        ];

        $items = [
            ['lat' => 45.51, 'lon' => 5.51],  // Near stage 1 midpoint
            ['lat' => 46.49, 'lon' => 6.49],  // Near stage 2 midpoint
        ];

        $result = $this->distributor->distributeByGeometry($items, $stages);

        $this->assertCount(1, $result[0]);
        $this->assertCount(1, $result[1]);
        $this->assertEqualsWithDelta(45.51, $result[0][0]['lat'], 0.001);
        $this->assertEqualsWithDelta(46.49, $result[1][0]['lat'], 0.001);
    }

    #[Test]
    public function distributeByGeometryFallsBackToStartAndEndPoints(): void
    {
        // No geometry provided -> fallback to [startPoint, endPoint]
        $stages = [
            new Stage('trip-1', 1, 80.0, 500.0, new Coordinate(45.0, 5.0), new Coordinate(46.0, 6.0)),
        ];

        $items = [
            ['lat' => 45.1, 'lon' => 5.1],
        ];

        $result = $this->distributor->distributeByGeometry($items, $stages);

        $this->assertCount(1, $result[0]);
    }

    #[Test]
    public function distributeByEndpointWithEmptyStages(): void
    {
        $items = [['lat' => 45.0, 'lon' => 5.0]];

        $result = $this->distributor->distributeByEndpoint($items, []);

        $this->assertSame([], $result);
    }

    #[Test]
    public function distributeByGeometryWithEmptyStages(): void
    {
        $items = [['lat' => 45.0, 'lon' => 5.0]];

        $result = $this->distributor->distributeByGeometry($items, []);

        $this->assertSame([], $result);
    }

    #[Test]
    public function distributeByEndpointInitializesAllStageKeys(): void
    {
        $stages = [
            new Stage('trip-1', 1, 80.0, 500.0, new Coordinate(45.0, 5.0), new Coordinate(45.5, 5.5)),
            new Stage('trip-1', 2, 80.0, 500.0, new Coordinate(45.5, 5.5), new Coordinate(46.0, 6.0)),
            new Stage('trip-1', 3, 80.0, 500.0, new Coordinate(46.0, 6.0), new Coordinate(46.5, 6.5)),
        ];

        // Only one item, close to stage 2
        $items = [['lat' => 45.9, 'lon' => 5.9]];

        $result = $this->distributor->distributeByEndpoint($items, $stages);

        // All 3 stage keys exist
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
    }
}
