<?php

declare(strict_types=1);

namespace App\Tests\Unit\InRide;

use App\Geo\GeoPoint;
use App\Geo\HaversineDistance;
use App\InRide\DetourCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DetourCalculatorTest extends TestCase
{
    private DetourCalculator $calculator;

    #[\Override]
    protected function setUp(): void
    {
        $this->calculator = new DetourCalculator(new HaversineDistance());
    }

    #[Test]
    public function emptyPolylineThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calculator->calculate(
            new GeoPoint(45.0, 5.0),
            new GeoPoint(45.001, 5.001),
            [],
        );
    }

    #[Test]
    public function poiOnRouteYieldsZeroDetour(): void
    {
        // West-east straight route along latitude 45.
        $route = [
            new GeoPoint(45.0, 5.000),
            new GeoPoint(45.0, 5.010),
            new GeoPoint(45.0, 5.020),
        ];
        $from = new GeoPoint(45.0, 5.000);
        $poi = new GeoPoint(45.0, 5.005); // directly on the first segment

        $result = $this->calculator->calculate($from, $poi, $route);

        // Detour must be (near) zero — going through the POI does not add length.
        $this->assertEqualsWithDelta(0.0, $result->detourMeters, 1.0);
        $this->assertEqualsWithDelta(45.0, $result->rejoinPoint->lat, 1e-6);
        $this->assertEqualsWithDelta(5.005, $result->rejoinPoint->lon, 1e-6);
        $this->assertSame(0, $result->segmentIndex);
        $this->assertFalse($result->poiFarFromRoute);
        $this->assertFalse($result->detourClampedToZero);
    }

    #[Test]
    public function poiToTheRightOfRouteProducesPositiveDetour(): void
    {
        // West-east straight route along latitude 45, POI offset to the north.
        $route = [
            new GeoPoint(45.0, 5.000),
            new GeoPoint(45.0, 5.020),
        ];
        $from = new GeoPoint(45.0, 5.000);
        // ~111m north of (45.0, 5.010): 0.001 deg lat ≈ 111 m.
        $poi = new GeoPoint(45.001, 5.010);

        $result = $this->calculator->calculate($from, $poi, $route);

        // Rejoin point should be the orthogonal projection on the segment ≈ (45.0, 5.010).
        $this->assertEqualsWithDelta(45.0, $result->rejoinPoint->lat, 1e-4);
        $this->assertEqualsWithDelta(5.010, $result->rejoinPoint->lon, 1e-4);
        $this->assertSame(0, $result->segmentIndex);

        // Detour roughly equals 2 × perpendicular distance (~222 m).
        $this->assertGreaterThan(150.0, $result->detourMeters);
        $this->assertLessThan(260.0, $result->detourMeters);
        $this->assertFalse($result->poiFarFromRoute);
        $this->assertFalse($result->detourClampedToZero);
    }

    #[Test]
    public function poiBehindPositionClampsDetourToZero(): void
    {
        // West-east route, rider already past the POI.
        $route = [
            new GeoPoint(45.0, 5.010),
            new GeoPoint(45.0, 5.020),
        ];
        $from = new GeoPoint(45.0, 5.010);
        $poi = new GeoPoint(45.0, 5.000); // behind the rider

        $result = $this->calculator->calculate($from, $poi, $route);

        $this->assertSame(0.0, $result->detourMeters);
        $this->assertTrue($result->detourClampedToZero);
        // Best rejoin point is the segment start (closest projection).
        $this->assertEqualsWithDelta(5.010, $result->rejoinPoint->lon, 1e-6);
    }

    #[Test]
    public function poiFarFromRouteRaisesWarningFlag(): void
    {
        // Route around (45.0, 5.0); POI ~10km north → far above 5km threshold.
        $route = [
            new GeoPoint(45.0, 5.000),
            new GeoPoint(45.0, 5.020),
        ];
        $from = new GeoPoint(45.0, 5.000);
        $poi = new GeoPoint(45.10, 5.010); // 0.10 deg lat ≈ 11 km north

        $result = $this->calculator->calculate($from, $poi, $route);

        $this->assertTrue($result->poiFarFromRoute);
        $this->assertGreaterThan(0.0, $result->detourMeters);
    }

    #[Test]
    public function picksClosestSegmentAcrossMultipleSegments(): void
    {
        // L-shaped polyline: first goes east, then turns north.
        $route = [
            new GeoPoint(45.000, 5.000),
            new GeoPoint(45.000, 5.020),
            new GeoPoint(45.020, 5.020),
        ];
        $from = new GeoPoint(45.000, 5.000);
        // POI sits just east of the vertical segment → should rejoin on segment index 1.
        $poi = new GeoPoint(45.010, 5.021);

        $result = $this->calculator->calculate($from, $poi, $route);

        $this->assertSame(1, $result->segmentIndex);
        $this->assertEqualsWithDelta(5.020, $result->rejoinPoint->lon, 1e-4);
        $this->assertEqualsWithDelta(45.010, $result->rejoinPoint->lat, 1e-4);
    }

    #[Test]
    public function singlePointPolylineUsesItAsRejoin(): void
    {
        $route = [new GeoPoint(45.0, 5.0)];
        $from = new GeoPoint(45.0, 5.0);
        $poi = new GeoPoint(45.001, 5.0);

        $result = $this->calculator->calculate($from, $poi, $route);

        $this->assertSame(0, $result->segmentIndex);
        $this->assertSame(45.0, $result->rejoinPoint->lat);
        $this->assertSame(5.0, $result->rejoinPoint->lon);
    }

    #[Test]
    public function straightLineToPoiMatchesHaversine(): void
    {
        $route = [
            new GeoPoint(45.0, 5.000),
            new GeoPoint(45.0, 5.020),
        ];
        $from = new GeoPoint(45.0, 5.000);
        $poi = new GeoPoint(45.001, 5.010);

        $result = $this->calculator->calculate($from, $poi, $route);

        $expected = (new HaversineDistance())->inMeters(45.0, 5.000, 45.001, 5.010);
        $this->assertEqualsWithDelta($expected, $result->straightLineToPoiMeters, 0.001);
    }
}
