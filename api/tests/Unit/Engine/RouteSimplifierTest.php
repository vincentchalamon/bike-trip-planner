<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engine;

use App\ApiResource\Model\Coordinate;
use App\Engine\RouteSimplifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteSimplifierTest extends TestCase
{
    private RouteSimplifier $simplifier;

    #[\Override]
    protected function setUp(): void
    {
        $this->simplifier = new RouteSimplifier();
    }

    #[Test]
    public function simplifyWithEmptyPoints(): void
    {
        $this->assertSame([], $this->simplifier->simplify([]));
    }

    #[Test]
    public function simplifyWithSinglePoint(): void
    {
        $points = [new Coordinate(45.0, 5.0)];

        $result = $this->simplifier->simplify($points);

        $this->assertCount(1, $result);
        $this->assertSame(45.0, $result[0]->lat);
    }

    #[Test]
    public function simplifyWithTwoPoints(): void
    {
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(46.0, 6.0),
        ];

        $result = $this->simplifier->simplify($points);

        $this->assertCount(2, $result);
    }

    #[Test]
    public function simplifyRemovesCollinearPoints(): void
    {
        // Points along a straight north line (same longitude)
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(45.05, 5.0),
            new Coordinate(45.1, 5.0),
            new Coordinate(45.15, 5.0),
            new Coordinate(45.2, 5.0),
        ];

        $result = $this->simplifier->simplify($points);

        // All intermediate points are collinear → should keep only start and end
        $this->assertCount(2, $result);
        $this->assertEqualsWithDelta(45.0, $result[0]->lat, 0.001);
        $this->assertEqualsWithDelta(45.2, $result[1]->lat, 0.001);
    }

    #[Test]
    public function simplifyKeepsSignificantDeviations(): void
    {
        // Create a route with a significant deviation in the middle
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(45.05, 5.0),
            new Coordinate(45.1, 5.5),   // Significant deviation east
            new Coordinate(45.15, 5.0),
            new Coordinate(45.2, 5.0),
        ];

        $result = $this->simplifier->simplify($points, 20.0);

        // The deviated point should be kept
        $this->assertGreaterThan(2, \count($result));
    }

    #[Test]
    public function simplifyWithCustomTolerance(): void
    {
        // Points with small deviation
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(45.05, 5.001),  // Tiny deviation
            new Coordinate(45.1, 5.0),
        ];

        // Very large tolerance: should simplify to 2 points
        $resultLarge = $this->simplifier->simplify($points, 100000.0);
        $this->assertCount(2, $resultLarge);

        // Very small tolerance: should keep all points
        $resultSmall = $this->simplifier->simplify($points, 0.001);
        $this->assertCount(3, $resultSmall);
    }

    #[Test]
    public function simplifyPreservesFirstAndLastPoints(): void
    {
        $points = [
            new Coordinate(45.0, 5.0, 100.0),
            new Coordinate(45.05, 5.05, 200.0),
            new Coordinate(45.1, 5.1, 300.0),
        ];

        $result = $this->simplifier->simplify($points);

        // First and last points always preserved
        $this->assertEqualsWithDelta(45.0, $result[0]->lat, 0.001);
        $this->assertEqualsWithDelta(45.1, $result[\count($result) - 1]->lat, 0.001);
    }

    #[Test]
    public function simplifyPreservesElevationData(): void
    {
        $points = [
            new Coordinate(45.0, 5.0, 100.0),
            new Coordinate(45.1, 5.5, 500.0),  // Significant deviation
            new Coordinate(45.2, 5.0, 200.0),
        ];

        $result = $this->simplifier->simplify($points, 1.0);

        // All points should be kept (significant deviation)
        $this->assertCount(3, $result);
        $this->assertEqualsWithDelta(100.0, $result[0]->ele, 0.01);
        $this->assertEqualsWithDelta(500.0, $result[1]->ele, 0.01);
        $this->assertEqualsWithDelta(200.0, $result[2]->ele, 0.01);
    }
}
