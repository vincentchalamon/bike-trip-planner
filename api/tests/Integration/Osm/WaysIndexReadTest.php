<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\Osm\WaysRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first ways read layer (ADR-040): seeds real
 * PostGIS LineStrings in osm.ways and asserts the ST_DWithin corridor filtering
 * plus the centroid / geography-length / tag projection the terrain analyzers consume.
 */
final class WaysIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        $this->connection->executeStatement('TRUNCATE osm.ways');

        // A secondary road on the corridor and a primary road ~50 km away.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.ways (osm_id, tags, geom) VALUES
              (1, '{"highway":"secondary","surface":"asphalt","maxspeed":"50"}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('LINESTRING(6.13 49.60, 6.15 49.62)'), 4326)),
              (2, '{"highway":"primary"}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('LINESTRING(6.80 49.90, 6.81 49.91)'), 4326))
            SQL);
    }

    #[Test]
    public function findInCorridorProjectsCentroidLengthAndTags(): void
    {
        $ways = new WaysRepository($this->connection)->findInCorridor([
            ['lat' => 49.60, 'lon' => 6.13],
            ['lat' => 49.62, 'lon' => 6.15],
        ], 100);

        // The far primary road is excluded by ST_DWithin.
        self::assertCount(1, $ways);

        $way = $ways[0];
        self::assertSame('secondary', $way['highway']);
        self::assertSame('asphalt', $way['surface']);
        self::assertSame('50', $way['maxspeed']);
        // Tags absent from the row default to '' (the shape the analyzers expect).
        self::assertSame('', $way['cycleway']);
        self::assertSame('', $way['bicycle']);
        // Centroid of the linestring + a real geography length in meters.
        self::assertEqualsWithDelta(49.61, $way['lat'], 0.01);
        self::assertEqualsWithDelta(6.14, $way['lon'], 0.01);
        self::assertGreaterThan(1000.0, $way['length']);
    }

    #[Test]
    public function emptyRouteYieldsNoQuery(): void
    {
        self::assertSame([], new WaysRepository($this->connection)->findInCorridor([], 100));
    }
}
