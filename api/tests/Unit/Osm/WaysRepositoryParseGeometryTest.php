<?php

declare(strict_types=1);

namespace App\Tests\Unit\Osm;

use App\Osm\WaysRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Direct coverage of the pure GeoJSON parsing, without a Postgres fixture: the
 * integration test only ever produces a single clipped LineString, so the
 * MultiLineString and the defensive branches are exercised here.
 */
final class WaysRepositoryParseGeometryTest extends TestCase
{
    #[Test]
    public function splitsAMultiLineStringIntoPolylinesAndFlipsToLatLon(): void
    {
        $geoJson = '{"type":"MultiLineString","coordinates":[[[6.14,49.61],[6.15,49.62]],[[6.20,49.65],[6.21,49.66]]]}';

        self::assertSame(
            [[[49.61, 6.14], [49.62, 6.15]], [[49.65, 6.20], [49.66, 6.21]]],
            WaysRepository::parseGeometry($geoJson),
        );
    }

    #[Test]
    public function wrapsABareLineStringAsASinglePolyline(): void
    {
        $geoJson = '{"type":"LineString","coordinates":[[6.14,49.61],[6.15,49.62]]}';

        self::assertSame(
            [[[49.61, 6.14], [49.62, 6.15]]],
            WaysRepository::parseGeometry($geoJson),
        );
    }

    /**
     * @param string $geoJson a geometry a corridor-boundary graze can produce
     */
    #[Test]
    #[DataProvider('degenerateGeometries')]
    public function yieldsNoPolylineForNonLineGeometry(string $geoJson): void
    {
        self::assertSame([], WaysRepository::parseGeometry($geoJson));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function degenerateGeometries(): iterable
    {
        yield 'empty string' => [''];
        yield 'malformed json' => ['{not json'];
        yield 'point' => ['{"type":"Point","coordinates":[6.14,49.61]}'];
        yield 'non-array coordinates' => ['{"type":"LineString","coordinates":null}'];
        yield 'geometry collection (no coordinates)' => ['{"type":"GeometryCollection","geometries":[]}'];
    }
}
