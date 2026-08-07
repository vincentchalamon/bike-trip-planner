<?php

declare(strict_types=1);

namespace App\Tests\Unit\InRide;

use App\Geo\GeoPoint;
use App\Geo\HaversineDistance;
use App\InRide\DetourCalculator;
use App\InRide\RouteTail;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteTailTest extends TestCase
{
    private RouteTail $routeTail;

    private DetourCalculator $detour;

    #[\Override]
    protected function setUp(): void
    {
        $this->routeTail = new RouteTail(new HaversineDistance());
        $this->detour = new DetourCalculator(new HaversineDistance());
    }

    #[Test]
    public function emptyPolylineYieldsEmptyTail(): void
    {
        $this->assertSame([], $this->routeTail->from(new GeoPoint(45.0, 5.0), []));
    }

    #[Test]
    public function singlePointPolylineYieldsThatPoint(): void
    {
        $tail = $this->routeTail->from(new GeoPoint(45.0, 5.0), [new GeoPoint(45.0, 5.001)]);

        $this->assertCount(1, $tail);
        $this->assertEqualsWithDelta(45.0, $tail[0]->lat, 1e-9);
        $this->assertEqualsWithDelta(5.001, $tail[0]->lon, 1e-9);
    }

    #[Test]
    public function riderMidSegmentStartsTheTailAtItsProjection(): void
    {
        // Straight west-east polyline along latitude 45.
        $polyline = [
            new GeoPoint(45.0, 5.000),
            new GeoPoint(45.0, 5.010),
            new GeoPoint(45.0, 5.020),
            new GeoPoint(45.0, 5.030),
        ];
        // Rider in the middle of segment 1 (5.010 → 5.020).
        $rider = new GeoPoint(45.0, 5.015);

        $tail = $this->routeTail->from($rider, $polyline);

        // Projection first, then the two points ahead — the two behind are dropped.
        $this->assertCount(3, $tail);
        $this->assertEqualsWithDelta(45.0, $tail[0]->lat, 1e-6);
        $this->assertEqualsWithDelta(5.015, $tail[0]->lon, 1e-6);
        $this->assertEqualsWithDelta(5.020, $tail[1]->lon, 1e-9);
        $this->assertEqualsWithDelta(5.030, $tail[2]->lon, 1e-9);
    }

    #[Test]
    public function riderOnFirstPointKeepsTheWholePolylineWithoutDuplicate(): void
    {
        $polyline = [
            new GeoPoint(45.0, 5.000),
            new GeoPoint(45.0, 5.010),
            new GeoPoint(45.0, 5.020),
        ];
        $rider = new GeoPoint(45.0, 5.000); // exactly on the first vertex

        $tail = $this->routeTail->from($rider, $polyline);

        $this->assertCount(3, $tail);
        $this->assertEqualsWithDelta(5.000, $tail[0]->lon, 1e-9);
        $this->assertEqualsWithDelta(5.010, $tail[1]->lon, 1e-9);
        $this->assertEqualsWithDelta(5.020, $tail[2]->lon, 1e-9);
    }

    #[Test]
    public function riderOnLastPointYieldsThatPointWithoutDuplicateOrEmptyTail(): void
    {
        $polyline = [
            new GeoPoint(45.0, 5.000),
            new GeoPoint(45.0, 5.010),
            new GeoPoint(45.0, 5.020),
        ];
        $rider = new GeoPoint(45.0, 5.020); // exactly on the last vertex

        $tail = $this->routeTail->from($rider, $polyline);

        $this->assertCount(1, $tail);
        $this->assertEqualsWithDelta(5.020, $tail[0]->lon, 1e-6);
    }

    /**
     * The reason RouteTail exists: a POI already passed must keep DetourCalculator's
     * "behind the rider" bound (detour clamped to 0, flag raised). With the entire
     * polyline the rider is in the middle so the bound is unreachable and the same POI
     * scores a positive detour.
     */
    #[Test]
    public function poiBehindRiderIsClampedWithTheTailButNotWithTheWholePolyline(): void
    {
        $polyline = [
            new GeoPoint(45.0, 5.000),
            new GeoPoint(45.0, 5.010),
            new GeoPoint(45.0, 5.020),
            new GeoPoint(45.0, 5.030),
        ];
        $rider = new GeoPoint(45.0, 5.015); // middle of the polyline
        // ~111 m north of 5.005, i.e. behind the rider and off the route.
        $poi = new GeoPoint(45.001, 5.005);

        $withWholePolyline = $this->detour->calculate($rider, $poi, $polyline);
        $this->assertFalse($withWholePolyline->detourClampedToZero);
        $this->assertGreaterThan(0.0, $withWholePolyline->detourMeters);

        $tail = $this->routeTail->from($rider, $polyline);
        $withTail = $this->detour->calculate($rider, $poi, $tail);
        $this->assertTrue($withTail->detourClampedToZero);
        $this->assertSame(0.0, $withTail->detourMeters);
    }
}
