<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Geo\HaversineDistance;
use App\Geo\Nearest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NearestTest extends TestCase
{
    #[Test]
    public function toReturnsTheClosestCandidateAndTheFirstOnATie(): void
    {
        $from = new Coordinate(48.0, 2.0);
        $candidates = [
            ['lat' => 48.1, 'lon' => 2.0, 'name' => 'far'],
            ['lat' => 48.01, 'lon' => 2.0, 'name' => 'near'],
            ['lat' => 48.01, 'lon' => 2.0, 'name' => 'tie'],
        ];

        self::assertSame('near', Nearest::to(new HaversineDistance(), $from, $candidates)['name'] ?? null);
        self::assertNull(Nearest::to(new HaversineDistance(), $from, []));
    }

    #[Test]
    public function anyWithinIsStrict(): void
    {
        $distance = new HaversineDistance();
        $from = new Coordinate(48.0, 2.0);
        $candidate = ['lat' => 48.01, 'lon' => 2.0];
        $meters = $distance->inMeters(48.0, 2.0, 48.01, 2.0);

        self::assertTrue(Nearest::anyWithin($distance, $from, [$candidate], $meters + 1.0));
        self::assertFalse(Nearest::anyWithin($distance, $from, [$candidate], $meters));
        self::assertFalse(Nearest::anyWithin($distance, $from, [], 1_000_000.0));
    }

    #[Test]
    public function vertexIndexAndDistanceToLine(): void
    {
        $distance = new HaversineDistance();
        $line = [new Coordinate(48.0, 2.0), new Coordinate(48.1, 2.0), new Coordinate(48.2, 2.0)];

        self::assertSame(1, Nearest::vertexIndex($distance, $line, 48.11, 2.0));
        self::assertSame(0, Nearest::vertexIndex($distance, [], 48.11, 2.0));
        self::assertEqualsWithDelta($distance->inMeters(48.1, 2.0, 48.11, 2.0), Nearest::distanceToLine($distance, $line, 48.11, 2.0), 0.001);
        self::assertSame(\PHP_FLOAT_MAX, Nearest::distanceToLine($distance, [], 48.11, 2.0));
    }

    #[Test]
    public function stageMidpointFallsBackToItsEndpoints(): void
    {
        $withGeometry = new Stage('t', 1, 10.0, 0.0, new Coordinate(1.0, 1.0), new Coordinate(3.0, 3.0), [
            new Coordinate(1.0, 1.0), new Coordinate(2.0, 2.0), new Coordinate(3.0, 3.0),
        ]);
        $withoutGeometry = new Stage('t', 2, 10.0, 0.0, new Coordinate(1.0, 1.0), new Coordinate(3.0, 3.0));

        self::assertSame(['lat' => 2.0, 'lon' => 2.0], $withGeometry->midpoint()->toLatLon());
        self::assertSame(['lat' => 3.0, 'lon' => 3.0], $withoutGeometry->midpoint()->toLatLon());
    }
}
