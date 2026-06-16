<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\Osm\CycleRouteRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first cycle-network read layer (ADR-040):
 * seeds a real PostGIS cycle route in osm.cycle_routes and asserts the
 * "on cycle network" fraction the API computes (length of the stage within a
 * tolerance of a cycle route over the stage length).
 */
final class CycleRouteIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    private const int TOLERANCE = 30;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        // A cycle route running due north along lon 2, from lat 48 to 49.
        $this->connection->executeStatement('TRUNCATE osm.cycle_routes');
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.cycle_routes (osm_id, name, network, ref, tags, geom) VALUES
              (1, 'EuroVelo Test', 'icn', 'EV-T', '{}'::jsonb,
                  ST_Multi(ST_SetSRID(ST_GeomFromText('LINESTRING(2 48, 2 49)'), 4326)))
            SQL);
    }

    #[Test]
    public function aStageFollowingACycleRouteIsFullyOnNetwork(): void
    {
        $fraction = new CycleRouteRepository($this->connection)->onNetworkFraction([
            ['lat' => 48.1, 'lon' => 2.0],
            ['lat' => 48.5, 'lon' => 2.0],
            ['lat' => 48.9, 'lon' => 2.0],
        ], self::TOLERANCE);

        self::assertGreaterThan(0.95, $fraction);
    }

    #[Test]
    public function aStageAwayFromAnyCycleRouteIsNotOnNetwork(): void
    {
        $fraction = new CycleRouteRepository($this->connection)->onNetworkFraction([
            ['lat' => 48.1, 'lon' => 10.0],
            ['lat' => 48.9, 'lon' => 10.0],
        ], self::TOLERANCE);

        self::assertEqualsWithDelta(0.0, $fraction, 0.0001);
    }

    #[Test]
    public function aStageHalfOnNetworkIsAroundOneHalf(): void
    {
        // First half follows the route (lon 2), second half veers away (lon 2.05 ~3.7km off).
        $fraction = new CycleRouteRepository($this->connection)->onNetworkFraction([
            ['lat' => 48.0, 'lon' => 2.0],
            ['lat' => 48.5, 'lon' => 2.0],
            ['lat' => 48.5, 'lon' => 2.05],
        ], self::TOLERANCE);

        self::assertGreaterThan(0.3, $fraction);
        self::assertLessThan(0.7, $fraction);
    }

    #[Test]
    public function aDegenerateStageIsNotOnNetwork(): void
    {
        $repository = new CycleRouteRepository($this->connection);

        self::assertSame(0.0, $repository->onNetworkFraction([], self::TOLERANCE));
        self::assertSame(0.0, $repository->onNetworkFraction([['lat' => 48.0, 'lon' => 2.0]], self::TOLERANCE));
    }
}
