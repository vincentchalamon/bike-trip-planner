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
 * PostGIS LineStrings in osm.ways and asserts the corridor filtering plus the
 * centroid / clipped-geography-length / tag projection the terrain analyzers consume.
 *
 * @phpstan-import-type WayRow from WaysRepository
 */
final class WaysIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    /** The production corridor half-width (AnalyzeTerrainHandler). */
    private const int RADIUS_METERS = 20;

    /** Route running along the seeded secondary road. */
    private const array CORRIDOR_ROUTE = [
        ['lat' => 49.60, 'lon' => 6.13],
        ['lat' => 49.62, 'lon' => 6.15],
    ];

    /** Straight west-east route at lat 49.60, ~7.2 km long, for the clipping cases. */
    private const array STRAIGHT_ROUTE = [
        ['lat' => 49.60, 'lon' => 6.10],
        ['lat' => 49.60, 'lon' => 6.20],
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
        $ways = new WaysRepository($this->connection)->findInCorridor(self::CORRIDOR_ROUTE, self::RADIUS_METERS);

        // The far primary road is outside the corridor.
        self::assertCount(1, $ways);

        $way = $ways[0];
        self::assertSame('secondary', $way['highway']);
        self::assertSame('asphalt', $way['surface']);
        self::assertSame('50', $way['maxspeed']);
        // Tags absent from the row default to '' (the shape the analyzers expect).
        self::assertSame('', $way['cycleway']);
        self::assertSame('', $way['bicycle']);
        self::assertSame('', $way['tracktype']);
        self::assertSame('', $way['smoothness']);
        // Centroid of the linestring + a real geography length in meters.
        self::assertEqualsWithDelta(49.61, $way['lat'], 0.01);
        self::assertEqualsWithDelta(6.14, $way['lon'], 0.01);
        self::assertGreaterThan(1000.0, $way['length']);
    }

    /**
     * The surface analyzer falls back on tracktype / smoothness when `surface` is
     * missing, so both tags must reach it from the jsonb column (issue #860).
     */
    #[Test]
    public function findInCorridorProjectsTracktypeAndSmoothness(): void
    {
        $this->connection->executeStatement('TRUNCATE osm.ways');
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.ways (osm_id, tags, geom) VALUES
              (20, '{"highway":"track","tracktype":"grade4","smoothness":"very_bad"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.13 49.60, 6.15 49.62)'), 4326))
            SQL);

        $ways = new WaysRepository($this->connection)->findInCorridor(self::CORRIDOR_ROUTE, self::RADIUS_METERS);

        self::assertCount(1, $ways);
        self::assertSame('', $ways[0]['surface']);
        self::assertSame('grade4', $ways[0]['tracktype']);
        self::assertSame('very_bad', $ways[0]['smoothness']);
    }

    /**
     * The measured length is the length of the portion running inside the corridor,
     * and only the ways the route actually follows are returned. Four cases on the
     * same straight route: followed end to end, followed for 200 m then leaving,
     * parallel inside the radius, parallel outside it.
     */
    #[Test]
    public function lengthIsClippedToTheCorridorAndParallelRoadsAreExcluded(): void
    {
        $this->connection->executeStatement('TRUNCATE osm.ways');
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.ways (osm_id, tags, geom) VALUES
              -- Followed end to end: the clipped length is the full length (~7229 m).
              (20, '{"highway":"residential","surface":"asphalt"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.10 49.60, 6.20 49.60)'), 4326)),
              -- 202 m along the route, then 5.5 km due south: a 5763 m way of which
              -- only the shared part (plus the 20 m the corridor extends over the
              -- turn) may be counted.
              (21, '{"highway":"unclassified","surface":"gravel"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.10 49.60, 6.1028 49.60, 6.1028 49.55)'), 4326)),
              -- Parallel 40 m north (the greenway-along-a-trunk-road false positive
              -- the 100 m corridor used to report): beyond the radius, dropped.
              (22, '{"highway":"trunk","surface":"asphalt"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.12 49.6003593, 6.18 49.6003593)'), 4326)),
              -- Parallel 10 m north: within GPS/decimation error, still followed.
              (23, '{"highway":"service","surface":"asphalt"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.12 49.6000898, 6.18 49.6000898)'), 4326))
            SQL);

        $ways = new WaysRepository($this->connection)->findInCorridor(self::STRAIGHT_ROUTE, self::RADIUS_METERS);

        $byHighway = [];
        foreach ($ways as $way) {
            $byHighway[$way['highway']] = $way;
        }

        // The trunk road running 40 m alongside is not part of the ride.
        $highways = array_keys($byHighway);
        sort($highways);
        self::assertSame(['residential', 'service', 'unclassified'], $highways);
        // Non-regression: a way followed from end to end keeps its full length.
        self::assertEqualsWithDelta(7228.9, $byHighway['residential']['length'], 1.0);
        // Clipped: 202 m shared + the 20 m of the southern branch inside the corridor,
        // instead of the 5763 m of the whole way.
        self::assertEqualsWithDelta(222.4, $byHighway['unclassified']['length'], 1.0);
        // The centroid describes the clipped portion too, so the stage distribution
        // sees the way where the route meets it, not 3 km south.
        self::assertEqualsWithDelta(49.60, $byHighway['unclassified']['lat'], 0.001);
        self::assertEqualsWithDelta(6.1015, $byHighway['unclassified']['lon'], 0.001);
        self::assertEqualsWithDelta(4337.3, $byHighway['service']['length'], 1.0);
    }

    /**
     * Behaviour guard for the index-friendly rewrite (ADR-043, PR1): the bbox
     * pre-filter must not change which ways the corridor scan returns. We seed a
     * varied network -- in/out of the corridor, mixed highway/surface tags, and a
     * way whose bounding box overlaps the corridor envelope yet sits ~600 m from
     * the route (a bbox false positive the metric predicate must still reject) --
     * and assert the optimised query returns exactly the same set as the same clip
     * run without the bbox pre-filter against the same data.
     */
    #[Test]
    public function indexFriendlyScanMatchesTheUnfilteredCorridorScan(): void
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
              -- On the corridor, surface tag missing, but carrying the
              -- tracktype/smoothness fallback signals.
              (12, '{"highway":"track","tracktype":"grade4","smoothness":"bad"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.140 49.610, 6.142 49.612)'), 4326)),
              -- Bbox false positive: within the padded envelope but ~600 m north of
              -- the route line, so excluded by the metric predicate.
              (13, '{"highway":"primary","surface":"asphalt"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.138 49.6165, 6.142 49.6165)'), 4326)),
              -- Far outside the corridor entirely.
              (14, '{"highway":"secondary"}'::jsonb,
                   ST_SetSRID(ST_GeomFromText('LINESTRING(6.80 49.90, 6.81 49.91)'), 4326))
            SQL);

        $expected = $this->unfilteredCorridorScan(self::CORRIDOR_ROUTE, self::RADIUS_METERS);
        $actual = new WaysRepository($this->connection)->findInCorridor(self::CORRIDOR_ROUTE, self::RADIUS_METERS);

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
        self::assertSame([], new WaysRepository($this->connection)->findInCorridor([], self::RADIUS_METERS));
    }

    /**
     * The same corridor clip without the index-usable bbox pre-filter, used as the
     * behaviour oracle. Projects the exact same columns/shape as WaysRepository so
     * the two results are directly comparable.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<WayRow>
     */
    private function unfilteredCorridorScan(array $route, int $radiusMeters): array
    {
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH ridden AS MATERIALIZED (
                    SELECT ST_Buffer(ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography, :radius)::geometry AS geom
                ),
                followed AS MATERIALIZED (
                    SELECT w.tags AS tags, ST_Intersection(w.geom, r.geom) AS geom
                    FROM osm.ways AS w, ridden AS r
                    WHERE ST_Intersects(w.geom, r.geom)
                )
                SELECT ST_Y(_c.centroid) AS lat,
                       ST_X(_c.centroid) AS lon,
                       _l.length AS length,
                       tags->>'surface' AS surface,
                       tags->>'tracktype' AS tracktype,
                       tags->>'smoothness' AS smoothness,
                       tags->>'highway' AS highway,
                       tags->>'cycleway' AS cycleway,
                       tags->>'cycleway:right' AS cycleway_right,
                       tags->>'cycleway:left' AS cycleway_left,
                       tags->>'cycleway:both' AS cycleway_both,
                       tags->>'bicycle' AS bicycle,
                       tags->>'maxspeed' AS maxspeed
                FROM followed AS f,
                     LATERAL (SELECT ST_Length(f.geom::geography) AS length) AS _l,
                     LATERAL (SELECT ST_Centroid(f.geom) AS centroid) AS _c
                WHERE _l.length > 0
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
                'tracktype' => (string) ($row['tracktype'] ?? ''),
                'smoothness' => (string) ($row['smoothness'] ?? ''),
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
