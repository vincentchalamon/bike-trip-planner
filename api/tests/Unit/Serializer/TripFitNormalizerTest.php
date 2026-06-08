<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Stage;
use App\ApiResource\Trip;
use App\Repository\TripRequestRepositoryInterface;
use App\Serializer\TripFitNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TripFitNormalizerTest extends TestCase
{
    #[Test]
    public function normalizeReturnsTitleAsCourseNameWithFlatPoints(): void
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

        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $repository->method('getStages')->willReturn([$stage1, $stage2]);
        $repository->method('getTitle')->willReturn('My Trip');

        $normalizer = new TripFitNormalizer($repository);
        $result = $normalizer->normalize(new Trip('trip-abc'), 'fit');

        self::assertIsArray($result);
        self::assertSame('My Trip', $result['courseName']);
        self::assertArrayNotHasKey('trackName', $result);
        /** @var list<array{lat: float, lon: float, ele: float|null}> $points */
        $points = $result['points'];
        self::assertCount(4, $points);
        self::assertSame(50.629, $points[0]['lat']);
        self::assertSame(50.800, $points[3]['lat']);
    }

    #[Test]
    public function normalizeMergesWaypointsFromAllStagesWithType(): void
    {
        $stage1 = new Stage(
            tripId: 'trip-abc',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(50.629, 3.057),
            endPoint: new Coordinate(50.700, 3.100),
        );
        $stage1->addPoi(new PointOfInterest('Bakery', 'bakery', 50.650, 3.070));

        $stage2 = new Stage(
            tripId: 'trip-abc',
            dayNumber: 2,
            distance: 60.0,
            elevation: 300.0,
            startPoint: new Coordinate(50.700, 3.100),
            endPoint: new Coordinate(50.800, 3.200),
        );
        $stage2->addAccommodation(new Accommodation('Hotel', 'hotel', 50.780, 3.190, 80.0, 120.0, false));

        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $repository->method('getStages')->willReturn([$stage1, $stage2]);

        $normalizer = new TripFitNormalizer($repository);
        $result = $normalizer->normalize(new Trip('trip-abc'), 'fit');

        /** @var list<array{name: string, type: string, lat: float, lon: float}> $waypoints */
        $waypoints = $result['waypoints'];
        self::assertCount(2, $waypoints);
        self::assertSame('Bakery', $waypoints[0]['name']);
        self::assertSame('bakery', $waypoints[0]['type']);
        self::assertSame('Hotel', $waypoints[1]['name']);
        self::assertSame('hotel', $waypoints[1]['type']);
    }

    #[Test]
    public function normalizeWithEmptyStagesReturnsEmptyPointsAndWaypoints(): void
    {
        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $repository->method('getStages')->willReturn([]);

        $normalizer = new TripFitNormalizer($repository);
        $result = $normalizer->normalize(new Trip('trip-abc'), 'fit');

        self::assertSame([], $result['points']);
        self::assertSame([], $result['waypoints']);
    }

    #[Test]
    public function supportsOnlyTripInFitFormat(): void
    {
        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $normalizer = new TripFitNormalizer($repository);

        $trip = new Trip('trip-abc');
        $stage = new Stage('t', 1, 1.0, 0.0, new Coordinate(0, 0), new Coordinate(0, 0));

        self::assertTrue($normalizer->supportsNormalization($trip, 'fit'));
        self::assertFalse($normalizer->supportsNormalization($trip, 'gpx'));
        self::assertFalse($normalizer->supportsNormalization($stage, 'fit'));
    }

    #[Test]
    public function normalizeWithInvalidDataThrowsException(): void
    {
        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $normalizer = new TripFitNormalizer($repository);

        $this->expectException(\InvalidArgumentException::class);
        $normalizer->normalize('not a trip', 'fit');
    }
}
