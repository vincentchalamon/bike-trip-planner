<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engine;

use Override;
use App\ApiResource\Model\Coordinate;
use App\Engine\DistanceCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DistanceCalculatorTest extends TestCase
{
    private DistanceCalculator $calculator;

    #[Override]
    protected function setUp(): void
    {
        $this->calculator = new DistanceCalculator();
    }

    #[Test]
    public function calculateTotalDistanceWithEmptyPoints(): void
    {
        $this->assertSame(0.0, $this->calculator->calculateTotalDistance([]));
    }

    #[Test]
    public function calculateTotalDistanceWithSinglePoint(): void
    {
        $this->assertSame(0.0, $this->calculator->calculateTotalDistance([
            new Coordinate(45.0, 5.0),
        ]));
    }

    #[Test]
    public function calculateTotalDistanceBetweenTwoPoints(): void
    {
        // Paris (48.8566, 2.3522) to Lyon (45.7640, 4.8357) ≈ 392 km
        $points = [
            new Coordinate(48.8566, 2.3522),
            new Coordinate(45.7640, 4.8357),
        ];

        $distance = $this->calculator->calculateTotalDistance($points);

        $this->assertEqualsWithDelta(392.0, $distance, 5.0);
    }

    #[Test]
    public function calculateTotalDistanceWithMultiplePoints(): void
    {
        // Three points along a route
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(45.1, 5.1),
            new Coordinate(45.2, 5.2),
        ];

        $distance = $this->calculator->calculateTotalDistance($points);

        // Sum of two segments should be greater than zero
        $this->assertGreaterThan(0.0, $distance);

        // Each segment ≈ 14 km, total ≈ 28 km
        $this->assertEqualsWithDelta(28.0, $distance, 3.0);
    }

    #[Test]
    public function distanceBetweenReturnsMeters(): void
    {
        $from = new Coordinate(45.0, 5.0);
        $to = new Coordinate(45.1, 5.1);

        $meters = $this->calculator->distanceBetween($from, $to);

        // ~14 km = ~14000 m
        $this->assertEqualsWithDelta(14000.0, $meters, 1000.0);
    }

    #[Test]
    public function distanceBetweenSamePointReturnsZero(): void
    {
        $point = new Coordinate(45.0, 5.0);

        $this->assertEqualsWithDelta(0.0, $this->calculator->distanceBetween($point, $point), 0.001);
    }

    #[Test]
    public function splitAtDistanceSplitsCorrectly(): void
    {
        // Create a track with known distances between points
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(45.1, 5.0),  // ~11 km north
            new Coordinate(45.2, 5.0),  // ~11 km north
            new Coordinate(45.3, 5.0),  // ~11 km north
        ];

        // Split at ~15 km from start index 0
        [$first, $second, $actual] = $this->calculator->splitAtDistance($points, 0, 15.0);

        $this->assertNotEmpty($first);
        $this->assertNotEmpty($second);
        $this->assertGreaterThanOrEqual(15.0, $actual);
        // First slice ends at or after the split point
        $this->assertGreaterThanOrEqual(2, \count($first));
        // Second slice starts from the split point
        $this->assertGreaterThanOrEqual(1, \count($second));
    }

    #[Test]
    public function splitAtDistanceFromNonZeroStartIndex(): void
    {
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(45.1, 5.0),
            new Coordinate(45.2, 5.0),
            new Coordinate(45.3, 5.0),
        ];

        // Split starting from index 1
        [$first, $second, $actual] = $this->calculator->splitAtDistance($points, 1, 15.0);

        // First slice should start from index 1
        $this->assertEqualsWithDelta(45.1, $first[0]->lat, 0.001);
        $this->assertGreaterThan(0.0, $actual);
    }

    #[Test]
    public function splitAtDistanceWhenTargetExceedsRemaining(): void
    {
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(45.1, 5.0),
        ];

        // Target 1000 km far exceeds ~11 km available
        [$first, $second, $actual] = $this->calculator->splitAtDistance($points, 0, 1000.0);

        // All points returned in first, empty remainder
        $this->assertCount(2, $first);
        $this->assertCount(0, $second);
        $this->assertGreaterThan(0.0, $actual);
    }

    #[Test]
    public function findClosestIndexReturnsCorrectIndex(): void
    {
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(46.0, 6.0),
            new Coordinate(47.0, 7.0),
        ];

        $target = new Coordinate(46.1, 6.1);

        $this->assertSame(1, $this->calculator->findClosestIndex($points, $target));
    }

    #[Test]
    public function findClosestIndexWithExactMatch(): void
    {
        $points = [
            new Coordinate(45.0, 5.0),
            new Coordinate(46.0, 6.0),
            new Coordinate(47.0, 7.0),
        ];

        $target = new Coordinate(47.0, 7.0);

        $this->assertSame(2, $this->calculator->findClosestIndex($points, $target));
    }

    #[Test]
    public function findClosestIndexWithSinglePoint(): void
    {
        $points = [new Coordinate(45.0, 5.0)];
        $target = new Coordinate(46.0, 6.0);

        $this->assertSame(0, $this->calculator->findClosestIndex($points, $target));
    }
}
