<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\ApiResource\TripRequest;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Stage;
use App\ApiResource\Trip;
use App\Serializer\TripGpxNormalizer;
use App\Serializer\TripExport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TripGpxNormalizerTest extends TestCase
{
    #[Test]
    public function normalizeReturnsTitleAsTrackNameWithFlatPoints(): void
    {
        $stage1 = new Stage(
            tripId: 'trip-abc',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(50.629, 3.057),
            endPoint: new Coordinate(50.700, 3.100),
            geometry: [new Coordinate(50.629, 3.057, 42.0), new Coordinate(50.700, 3.100, 50.0)],
        );

        $stage2 = new Stage(
            tripId: 'trip-abc',
            dayNumber: 2,
            distance: 60.0,
            elevation: 300.0,
            startPoint: new Coordinate(50.700, 3.100),
            endPoint: new Coordinate(50.800, 3.200),
            geometry: [new Coordinate(50.700, 3.100, 50.0), new Coordinate(50.800, 3.200, 60.0)],
        );


        $normalizer = new TripGpxNormalizer();
        $trip = new Trip('trip-abc', computationStatus: [], isLocked: false, export: new TripExport('My Trip', null, [$stage1, $stage2]));
        $result = $normalizer->normalize($trip, 'gpx');

        self::assertIsArray($result);
        self::assertSame('My Trip', $result['trackName']);
        self::assertArrayHasKey('points', $result);
        /** @var list<array{lat: float, lon: float, ele: float|null}> $points */
        $points = $result['points'];
        self::assertCount(4, $points);
        self::assertSame(50.629, $points[0]['lat']);
        self::assertSame(50.700, $points[1]['lat']);
        self::assertSame(50.700, $points[2]['lat']);
        self::assertSame(50.800, $points[3]['lat']);
    }

    #[Test]
    public function normalizeMergesWaypointsFromAllStages(): void
    {
        $stage1 = new Stage(
            tripId: 'trip-abc',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(50.629, 3.057),
            endPoint: new Coordinate(50.700, 3.100),
        );
        $stage1->resupply = new Resupply(foodAtLunch: [new PointOfInterest('Bakery', 'bakery', 50.650, 3.070)]);

        $stage2 = new Stage(
            tripId: 'trip-abc',
            dayNumber: 2,
            distance: 60.0,
            elevation: 300.0,
            startPoint: new Coordinate(50.700, 3.100),
            endPoint: new Coordinate(50.800, 3.200),
        );
        $stage2->addAccommodation(new Accommodation('Hotel', 'hotel', 50.780, 3.190, 80.0, 120.0, false));


        $normalizer = new TripGpxNormalizer();
        $trip = new Trip('trip-abc', computationStatus: [], isLocked: false, export: new TripExport('trip-abc', null, [$stage1, $stage2]));
        $result = $normalizer->normalize($trip, 'gpx');

        /** @var list<array{name: string, lat: float, lon: float}> $waypoints */
        $waypoints = $result['waypoints'];
        self::assertCount(2, $waypoints);
        self::assertSame('Bakery', $waypoints[0]['name']);
        self::assertSame('Hotel', $waypoints[1]['name']);
    }

    #[Test]
    public function normalizeWithEmptyStagesReturnsEmptyPointsAndWaypoints(): void
    {

        $normalizer = new TripGpxNormalizer();
        $trip = new Trip('trip-abc', computationStatus: [], isLocked: false, export: new TripExport('trip-abc', null, []));
        $result = $normalizer->normalize($trip, 'gpx');

        self::assertSame([], $result['points']);
        self::assertSame([], $result['waypoints']);
    }

    #[Test]
    public function supportsOnlyTripInGpxFormat(): void
    {
        $normalizer = new TripGpxNormalizer();

        $trip = new Trip('trip-abc', computationStatus: [], isLocked: false);
        $stage = new Stage('t', 1, 1.0, 0.0, new Coordinate(0, 0), new Coordinate(0, 0));

        self::assertTrue($normalizer->supportsNormalization($trip, 'gpx'));
        self::assertFalse($normalizer->supportsNormalization($trip, 'json'));
        self::assertFalse($normalizer->supportsNormalization($stage, 'gpx'));
    }

    #[Test]
    public function normalizeIncludesSourceUrlFromRequest(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/12345';


        $normalizer = new TripGpxNormalizer();
        $result = $normalizer->normalize(new Trip('trip-abc', computationStatus: [], isLocked: false, export: new TripExport('trip-abc', $request->sourceUrl, [])), 'gpx');

        self::assertSame('https://www.komoot.com/tour/12345', $result['sourceUrl']);
    }

    #[Test]
    public function aTripLoadedWithoutItsExportIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        new TripGpxNormalizer()->normalize(new Trip('trip-abc', computationStatus: [], isLocked: false), 'gpx');
    }

    #[Test]
    public function normalizeWithInvalidDataThrowsException(): void
    {
        $normalizer = new TripGpxNormalizer();

        $this->expectException(\InvalidArgumentException::class);
        $normalizer->normalize('not a trip', 'gpx');
    }
}
