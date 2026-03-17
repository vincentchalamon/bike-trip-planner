<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Stage;
use App\ApiResource\Trip;
use App\Repository\TripRequestRepositoryInterface;
use App\Serializer\TripGpxNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TripGpxNormalizerTest extends TestCase
{
    #[Test]
    public function normalizeReturnsTripIdAsTrackNameWithMultipleSegments(): void
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

        $repository = $this->createMock(TripRequestRepositoryInterface::class);
        $repository->method('getStages')->with('trip-abc')->willReturn([$stage1, $stage2]);

        $normalizer = new TripGpxNormalizer($repository);
        $trip = new Trip('trip-abc');
        $result = $normalizer->normalize($trip, 'gpx');

        self::assertIsArray($result);
        self::assertSame('trip-abc', $result['trackName']);
        self::assertArrayHasKey('segments', $result);
        self::assertCount(2, $result['segments']);
        self::assertCount(2, $result['segments'][0]);
        self::assertCount(2, $result['segments'][1]);
        self::assertSame(50.629, $result['segments'][0][0]['lat']);
        self::assertSame(50.800, $result['segments'][1][1]['lat']);
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

        $repository = $this->createMock(TripRequestRepositoryInterface::class);
        $repository->method('getStages')->with('trip-abc')->willReturn([$stage1, $stage2]);

        $normalizer = new TripGpxNormalizer($repository);
        $trip = new Trip('trip-abc');
        $result = $normalizer->normalize($trip, 'gpx');

        self::assertCount(2, $result['waypoints']);
        self::assertSame('Bakery', $result['waypoints'][0]['name']);
        self::assertSame('Hotel', $result['waypoints'][1]['name']);
    }

    #[Test]
    public function normalizeWithEmptyStagesReturnsEmptySegmentsAndWaypoints(): void
    {
        $repository = $this->createMock(TripRequestRepositoryInterface::class);
        $repository->method('getStages')->with('trip-abc')->willReturn([]);

        $normalizer = new TripGpxNormalizer($repository);
        $trip = new Trip('trip-abc');
        $result = $normalizer->normalize($trip, 'gpx');

        self::assertSame([], $result['segments']);
        self::assertSame([], $result['waypoints']);
    }

    #[Test]
    public function supportsOnlyTripInGpxFormat(): void
    {
        $repository = $this->createMock(TripRequestRepositoryInterface::class);
        $normalizer = new TripGpxNormalizer($repository);

        $trip = new Trip('trip-abc');
        $stage = new Stage('t', 1, 1.0, 0.0, new Coordinate(0, 0), new Coordinate(0, 0));

        self::assertTrue($normalizer->supportsNormalization($trip, 'gpx'));
        self::assertFalse($normalizer->supportsNormalization($trip, 'json'));
        self::assertFalse($normalizer->supportsNormalization($stage, 'gpx'));
    }

    #[Test]
    public function normalizeWithInvalidDataThrowsException(): void
    {
        $repository = $this->createMock(TripRequestRepositoryInterface::class);
        $normalizer = new TripGpxNormalizer($repository);

        $this->expectException(\InvalidArgumentException::class);
        $normalizer->normalize('not a trip', 'gpx'); // @phpstan-ignore argument.type
    }
}
