<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Stage;
use App\Serializer\GpxNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GpxNormalizerTest extends TestCase
{
    #[Test]
    public function normalizeReturnsArrayWithTrackNamePointsAndWaypoints(): void
    {
        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(50.629, 3.057),
            endPoint: new Coordinate(50.700, 3.100),
            geometry: [new Coordinate(50.629, 3.057, 42.0), new Coordinate(50.700, 3.100, 50.0)],
        );

        $stage->addPoi(new PointOfInterest('Bakery Test', 'bakery', 50.650, 3.070));
        $stage->addAccommodation(new Accommodation(
            'Camping Test',
            'camp_site',
            50.690,
            3.095,
            10.0,
            15.0,
            false,
        ));

        $normalizer = new GpxNormalizer();
        $result = $normalizer->normalize($stage, 'gpx');

        self::assertIsArray($result);
        self::assertSame('Stage 1', $result['trackName']);

        // Points
        self::assertCount(2, $result['points']);
        self::assertSame(50.629, $result['points'][0]['lat']);
        self::assertSame(3.057, $result['points'][0]['lon']);
        self::assertSame(42.0, $result['points'][0]['ele']);

        // Waypoints with symbol mapping
        self::assertCount(2, $result['waypoints']);
        self::assertSame('Bakery Test', $result['waypoints'][0]['name']);
        self::assertSame('Shopping Center', $result['waypoints'][0]['symbol']);
        self::assertSame('bakery', $result['waypoints'][0]['type']);

        self::assertSame('Camping Test', $result['waypoints'][1]['name']);
        self::assertSame('Campground', $result['waypoints'][1]['symbol']);
        self::assertSame('camp_site', $result['waypoints'][1]['type']);
    }

    #[Test]
    public function normalizeWithoutPoisProducesEmptyWaypoints(): void
    {
        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(50.629, 3.057),
            endPoint: new Coordinate(50.700, 3.100),
        );

        $normalizer = new GpxNormalizer();
        $result = $normalizer->normalize($stage, 'gpx');

        self::assertSame([], $result['waypoints']);
        self::assertCount(2, $result['points']);
    }

    #[Test]
    public function normalizeUsesLabelWhenPresent(): void
    {
        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(50.629, 3.057),
            endPoint: new Coordinate(50.700, 3.100),
            label: 'Lille → Arras',
        );

        $normalizer = new GpxNormalizer();
        $result = $normalizer->normalize($stage, 'gpx');

        self::assertSame('Lille → Arras', $result['trackName']);
    }

    #[Test]
    public function supportsGpxFormatOnly(): void
    {
        $normalizer = new GpxNormalizer();

        $stage = new Stage('t', 1, 1.0, 0.0, new Coordinate(0, 0), new Coordinate(0, 0));
        self::assertTrue($normalizer->supportsNormalization($stage, 'gpx'));
        self::assertFalse($normalizer->supportsNormalization($stage, 'json'));
        self::assertFalse($normalizer->supportsNormalization($stage, 'fit'));
    }
}
