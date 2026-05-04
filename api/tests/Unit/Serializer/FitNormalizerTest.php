<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Stage;
use App\Serializer\FitNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FitNormalizerTest extends TestCase
{
    #[Test]
    public function normalizeReturnsArrayWithCourseNamePointsAndWaypoints(): void
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

        $normalizer = new FitNormalizer();
        $result = $normalizer->normalize($stage, 'fit');

        self::assertIsArray($result);
        \assert(\is_array($result['points']) && \is_array($result['points'][0]));
        \assert(\is_array($result['waypoints']) && \is_array($result['waypoints'][0]) && \is_array($result['waypoints'][1]));
        self::assertSame('Stage 1', $result['courseName']);

        // Points
        self::assertCount(2, $result['points']);
        self::assertSame(50.629, $result['points'][0]['lat']);

        // Waypoints contain raw type, no symbol
        self::assertCount(2, $result['waypoints']);
        self::assertSame('bakery', $result['waypoints'][0]['type']);
        self::assertArrayNotHasKey('symbol', $result['waypoints'][0]);
        self::assertSame('camp_site', $result['waypoints'][1]['type']);
    }

    #[Test]
    public function supportsFitFormatOnly(): void
    {
        $normalizer = new FitNormalizer();

        $stage = new Stage('t', 1, 1.0, 0.0, new Coordinate(0, 0), new Coordinate(0, 0));
        self::assertTrue($normalizer->supportsNormalization($stage, 'fit'));
        self::assertFalse($normalizer->supportsNormalization($stage, 'json'));
        self::assertFalse($normalizer->supportsNormalization($stage, 'gpx'));
    }
}
