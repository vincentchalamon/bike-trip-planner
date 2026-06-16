<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\Osm\CoverageRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first coverage-polygon read layer (ADR-040):
 * seeds the single-row osm.coverage table and asserts the ST_Covers out-of-zone
 * test the API uses to flag display-only trips, including the "unknown coverage"
 * fallbacks (empty table / NULL geom) that must never block the user.
 */
final class CoverageIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        // A 2x2 square around (2..4 lon, 48..50 lat): the provisioned coverage area.
        $this->seedCoverage("ST_GeomFromText('MULTIPOLYGON(((2 48, 4 48, 4 50, 2 50, 2 48)))')");
    }

    private function seedCoverage(string $geomExpr): void
    {
        $this->connection->executeStatement('TRUNCATE osm.coverage');
        $this->connection->executeStatement(\sprintf(
            'INSERT INTO osm.coverage (geom) VALUES (ST_Multi(ST_SetSRID(%s, 4326)))',
            $geomExpr,
        ));
    }

    #[Test]
    public function routeFullyInsideCoverageIsInZone(): void
    {
        $repository = new CoverageRepository($this->connection);

        self::assertFalse($repository->isRouteOutOfZone([
            ['lat' => 48.5, 'lon' => 2.5],
            ['lat' => 49.0, 'lon' => 3.0],
            ['lat' => 49.5, 'lon' => 3.5],
        ]));
    }

    #[Test]
    public function routeLeavingCoverageIsOutOfZone(): void
    {
        $repository = new CoverageRepository($this->connection);

        // Starts inside, ends well outside the square.
        self::assertTrue($repository->isRouteOutOfZone([
            ['lat' => 48.5, 'lon' => 2.5],
            ['lat' => 48.5, 'lon' => 10.0],
        ]));
    }

    #[Test]
    public function singlePointOutsideCoverageIsOutOfZone(): void
    {
        self::assertTrue(new CoverageRepository($this->connection)->isRouteOutOfZone([
            ['lat' => 0.0, 'lon' => 0.0],
        ]));
    }

    #[Test]
    public function emptyRouteIsNeverOutOfZone(): void
    {
        self::assertFalse(new CoverageRepository($this->connection)->isRouteOutOfZone([]));
    }

    #[Test]
    public function unprovisionedCoverageIsNeverOutOfZone(): void
    {
        // No coverage row at all (index never provisioned): unknown coverage must
        // not flag an otherwise valid trip as out of zone.
        $this->connection->executeStatement('TRUNCATE osm.coverage');

        self::assertFalse(new CoverageRepository($this->connection)->isRouteOutOfZone([
            ['lat' => 0.0, 'lon' => 0.0],
        ]));
    }

    #[Test]
    public function nullCoverageGeometryIsNeverOutOfZone(): void
    {
        // A coverage row with NULL geom (ST_Union over zero boundaries) is also
        // treated as unknown.
        $this->connection->executeStatement('TRUNCATE osm.coverage');
        $this->connection->executeStatement('INSERT INTO osm.coverage (geom) VALUES (NULL)');

        self::assertFalse(new CoverageRepository($this->connection)->isRouteOutOfZone([
            ['lat' => 0.0, 'lon' => 0.0],
        ]));
    }
}
