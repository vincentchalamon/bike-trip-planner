<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\Osm\ChargingStationRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first e-bike charging read layer (ADR-040):
 * exercises the real PostGIS `osm.charging_stations` table with seeded rows and
 * asserts the ST_DWithin corridor filtering used by the e-bike-range alert.
 */
final class ChargingStationIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        // osm.* are not Doctrine entities, so the reset does not clear them.
        $this->connection->executeStatement('TRUNCATE osm.charging_stations');
    }

    #[Test]
    public function returnsChargingStationsWithinTheCorridor(): void
    {
        // Near charger sits on the route corridor; the far one (~60 km away) is excluded.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO osm.charging_stations (osm_type, osm_id, name, category, tags, geom)
                VALUES ('n', 1, 'On Route Charger', 'charging_station', '{}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326))
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO osm.charging_stations (osm_type, osm_id, name, category, tags, geom)
                VALUES ('n', 2, 'Far Charger', 'charging_station', '{}'::jsonb, ST_SetSRID(ST_MakePoint(6.80, 49.90), 4326))
                SQL,
        );

        $stations = new ChargingStationRepository($this->connection)->findInCorridor([
            ['lat' => 49.60, 'lon' => 6.13],
            ['lat' => 49.62, 'lon' => 6.15],
        ], 2000);

        // The far charger is excluded by ST_DWithin.
        self::assertCount(1, $stations);
        self::assertSame('On Route Charger', $stations[0]['name']);
        self::assertSame('charging_station', $stations[0]['category']);
        self::assertEqualsWithDelta(49.61, $stations[0]['lat'], 0.0001);
        self::assertEqualsWithDelta(6.14, $stations[0]['lon'], 0.0001);
    }
}
