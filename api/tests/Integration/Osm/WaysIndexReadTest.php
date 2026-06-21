<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\Osm\WaysRepository;
use App\Osm\WktGeometry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first ways read layer (ADR-040): seeds real
 * PostGIS LineStrings in osm.ways and asserts the ST_DWithin corridor filtering
 * plus the centroid / geography-length / tag projection the terrain analyzers consume.
 *
 * @phpstan-import-type WayRow from WaysRepository
 */
final class WaysIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    /** Route running along the seeded secondary road. */
    private const array CORRIDOR_ROUTE = [
        ['lat' => 49.60, 'lon' => 6.13],
        ['lat' => 49.62, 'lon' => 6.15],
    ];

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
        $ways = new WaysRepository($this->connection)->findInCorridor(self::CORRIDOR_ROUTE, 100);

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

    /**
     * Behaviour guard for the index-friendly rewrite (ADR-043, PR1): the bbox
     * pre-filter must not change which ways the corridor scan returns. We seed a
     * varied network -- in/out of the corridor, mixed highway/surface tags, and a
     * way whose bounding box overlaps the corridor envelope yet sits >100 m from
     * the route (a bbox false positive the metric ST_DWithin must still reject) --
     * and assert the optimised query returns exactly the same set as the original
     * naive `geom::geography` scan run against the same data.
     */
    #[Test]
    public function indexFriendlyScanMatchesTheNaiveCorridorScan(): void
    {
        $this->connection->executeStatement('TRUNCATE osm.ways');
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.ways (osm_id, tags, geom) VALUES
              -- On the corridor, paved.
              (10, '{"highway":"tertiary","surface":"asphalt","maxspeed":"70"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.131 49.601, 6.149 49.619)'), 4326)),
              -- On the corridor, unpaved, no surface-less tags.
              (11, '{"highway":"track","surface":"gravel"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.135 49.605, 6.145 49.615)'), 4326)),
              -- On the corridor, surface tag missing (counts toward missing-data %).
              (12, '{"highway":"residential"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.140 49.610, 6.142 49.612)'), 4326)),
              -- Bbox false positive: within the padded envelope but ~600 m north of
              -- the route line, so excluded by the 100 m metric predicate.
              (13, '{"highway":"primary","surface":"asphalt"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.138 49.6165, 6.142 49.6165)'), 4326)),
              -- Far outside the corridor entirely.
              (14, '{"highway":"secondary"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.80 49.90, 6.81 49.91)'), 4326))
            SQL);

        $expected = $this->naiveCorridorScan(self::CORRIDOR_ROUTE, 100);
        $actual = new WaysRepository($this->connection)->findInCorridor(self::CORRIDOR_ROUTE, 100);

        // Order is not guaranteed by either query; compare as sets keyed by centroid.
        usort($expected, $this->byCentroid(...));
        usort($actual, $this->byCentroid(...));

        self::assertSame($expected, $actual);
        // Sanity: the seeding actually exercises the corridor (3 on-route ways),
        // so the assertion above is not vacuously comparing two empty sets.
        self::assertCount(3, $actual);
    }

    #[Test]
    public function emptyRouteYieldsNoQuery(): void
    {
        self::assertSame([], new WaysRepository($this->connection)->findInCorridor([], 100));
    }

    /**
     * The pre-optimisation query (per-row `geom::geography`, no bbox pre-filter),
     * used as the behaviour oracle. Projects the exact same columns/shape as
     * WaysRepository so the two results are directly comparable.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<WayRow>
     */
    private function naiveCorridorScan(array $route, int $radiusMeters): array
    {
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT ST_Y(_c.centroid) AS lat,
                       ST_X(_c.centroid) AS lon,
                       ST_Length(geom::geography) AS length,
                       tags->>'surface' AS surface,
                       tags->>'highway' AS highway,
                       tags->>'cycleway' AS cycleway,
                       tags->>'cycleway:right' AS cycleway_right,
                       tags->>'cycleway:left' AS cycleway_left,
                       tags->>'cycleway:both' AS cycleway_both,
                       tags->>'bicycle' AS bicycle,
                       tags->>'maxspeed' AS maxspeed
                FROM osm.ways,
                LATERAL (SELECT ST_Centroid(geom) AS centroid) AS _c
                WHERE ST_DWithin(
                    geom::geography,
                    ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography,
                    :radius
                )
                SQL,
            [
                'wkt' => WktGeometry::lineStringOrPoint($route),
                'radius' => $radiusMeters,
            ],
        );

        $ways = [];
        foreach ($rows as $row) {
            $ways[] = [
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'surface' => (string) ($row['surface'] ?? ''),
                'highway' => (string) ($row['highway'] ?? ''),
                'cycleway' => (string) ($row['cycleway'] ?? ''),
                'cycleway:right' => (string) ($row['cycleway_right'] ?? ''),
                'cycleway:left' => (string) ($row['cycleway_left'] ?? ''),
                'cycleway:both' => (string) ($row['cycleway_both'] ?? ''),
                'bicycle' => (string) ($row['bicycle'] ?? ''),
                'maxspeed' => (string) ($row['maxspeed'] ?? ''),
                'length' => (float) $row['length'],
            ];
        }

        return $ways;
    }

    /**
     * @param WayRow $a
     * @param WayRow $b
     */
    private function byCentroid(array $a, array $b): int
    {
        return [$a['lat'], $a['lon']] <=> [$b['lat'], $b['lon']];
    }
}
