<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engine;

use App\ApiResource\Model\Coordinate;
use App\Engine\ElevationCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ElevationCalculatorTest extends TestCase
{
    private ElevationCalculator $calculator;

    #[\Override]
    protected function setUp(): void
    {
        $this->calculator = new ElevationCalculator();
    }

    #[Test]
    public function calculateTotalAscentWithEmptyPoints(): void
    {
        $this->assertSame(0.0, $this->calculator->calculateTotalAscent([]));
    }

    #[Test]
    public function calculateTotalAscentWithSinglePoint(): void
    {
        $this->assertSame(0.0, $this->calculator->calculateTotalAscent([
            new Coordinate(45.0, 5.0, 100.0),
        ]));
    }

    #[Test]
    public function calculateTotalAscentWithSteadyClimb(): void
    {
        $points = [
            new Coordinate(45.0, 5.0, 100.0),
            new Coordinate(45.1, 5.0, 200.0),
            new Coordinate(45.2, 5.0, 300.0),
            new Coordinate(45.3, 5.0, 400.0),
        ];

        // Total ascent: 300m (one continuous climb > 3m threshold)
        $this->assertEqualsWithDelta(300.0, $this->calculator->calculateTotalAscent($points), 0.01);
    }

    #[Test]
    public function calculateTotalAscentFiltersNoiseBelow3m(): void
    {
        $points = [
            new Coordinate(45.0, 5.0, 100.0),
            new Coordinate(45.1, 5.0, 102.0),  // +2m (noise)
            new Coordinate(45.2, 5.0, 100.0),  // -2m (direction change, flush: 2m < 3m threshold → discarded)
            new Coordinate(45.3, 5.0, 110.0),  // +10m
        ];

        // Only the 10m gain (100→110) exceeds threshold
        $this->assertEqualsWithDelta(10.0, $this->calculator->calculateTotalAscent($points), 0.01);
    }

    #[Test]
    public function calculateTotalAscentWithMixedTerrain(): void
    {
        $points = [
            new Coordinate(45.0, 5.0, 100.0),
            new Coordinate(45.1, 5.0, 200.0),  // +100m
            new Coordinate(45.2, 5.0, 150.0),  // -50m (direction change → flush 100m)
            new Coordinate(45.3, 5.0, 250.0),  // +100m
        ];

        // Two climbs: 100m + 100m = 200m total ascent
        $this->assertEqualsWithDelta(200.0, $this->calculator->calculateTotalAscent($points), 0.01);
    }

    #[Test]
    public function calculateTotalAscentFlushesFinalSegment(): void
    {
        $points = [
            new Coordinate(45.0, 5.0, 100.0),
            new Coordinate(45.1, 5.0, 200.0),
        ];

        // Single ascending segment, flushed at end: 100m > 3m threshold
        $this->assertEqualsWithDelta(100.0, $this->calculator->calculateTotalAscent($points), 0.01);
    }

    #[Test]
    public function calculateTotalDescentWithEmptyPoints(): void
    {
        $this->assertSame(0.0, $this->calculator->calculateTotalDescent([]));
    }

    #[Test]
    public function calculateTotalDescentWithSteadyDescent(): void
    {
        $points = [
            new Coordinate(45.0, 5.0, 400.0),
            new Coordinate(45.1, 5.0, 300.0),
            new Coordinate(45.2, 5.0, 200.0),
            new Coordinate(45.3, 5.0, 100.0),
        ];

        // Total descent: 300m (one continuous descent > 3m threshold)
        $this->assertEqualsWithDelta(300.0, $this->calculator->calculateTotalDescent($points), 0.01);
    }

    #[Test]
    public function calculateTotalDescentFiltersNoiseBelow3m(): void
    {
        $points = [
            new Coordinate(45.0, 5.0, 100.0),
            new Coordinate(45.1, 5.0, 98.0),   // -2m (noise)
            new Coordinate(45.2, 5.0, 100.0),  // +2m (direction change, flush: 2m < 3m → discarded)
            new Coordinate(45.3, 5.0, 90.0),   // -10m
        ];

        // Only the 10m loss exceeds threshold
        $this->assertEqualsWithDelta(10.0, $this->calculator->calculateTotalDescent($points), 0.01);
    }

    #[Test]
    public function calculateTotalDescentWithMixedTerrain(): void
    {
        $points = [
            new Coordinate(45.0, 5.0, 300.0),
            new Coordinate(45.1, 5.0, 200.0),  // -100m
            new Coordinate(45.2, 5.0, 250.0),  // +50m (direction change → flush 100m)
            new Coordinate(45.3, 5.0, 150.0),  // -100m
        ];

        // Two descents: 100m + 100m = 200m total
        $this->assertEqualsWithDelta(200.0, $this->calculator->calculateTotalDescent($points), 0.01);
    }

    #[Test]
    public function calculateTotalDescentFlushesFinalSegment(): void
    {
        $points = [
            new Coordinate(45.0, 5.0, 200.0),
            new Coordinate(45.1, 5.0, 100.0),
        ];

        // Single descending segment, flushed at end: 100m > 3m threshold
        $this->assertEqualsWithDelta(100.0, $this->calculator->calculateTotalDescent($points), 0.01);
    }

    #[Test]
    public function flatTerrainReturnsZeroForBothAscentAndDescent(): void
    {
        $points = [
            new Coordinate(45.0, 5.0, 100.0),
            new Coordinate(45.1, 5.0, 100.0),
            new Coordinate(45.2, 5.0, 100.0),
        ];

        $this->assertSame(0.0, $this->calculator->calculateTotalAscent($points));
        $this->assertSame(0.0, $this->calculator->calculateTotalDescent($points));
    }
}
