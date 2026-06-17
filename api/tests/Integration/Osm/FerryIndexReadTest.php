<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\Osm\FerryRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first ferry read layer (ADR-040): seeds a
 * real PostGIS ferry line in osm.ferries and asserts the proximity detection the
 * ferry-crossing alert relies on (a stage whose route follows the ferry line).
 */
final class FerryIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    private const int TOLERANCE = 100;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        // A ferry crossing running east along lat 47, from lon 2.0 to 2.1.
        $this->connection->executeStatement('TRUNCATE osm.ferries');
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.ferries (osm_type, osm_id, name, tags, geom) VALUES
              ('W', 1, 'Bac de Test', '{}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('LINESTRING(2.0 47.0, 2.1 47.0)'), 4326))
            SQL);
    }

    #[Test]
    public function aStageFollowingTheFerryDetectsIt(): void
    {
        $ferries = new FerryRepository($this->connection)->findNearStage([
            ['lat' => 47.0, 'lon' => 2.02],
            ['lat' => 47.0, 'lon' => 2.08],
        ], self::TOLERANCE);

        self::assertCount(1, $ferries);
        self::assertSame('Bac de Test', $ferries[0]['name']);
        self::assertEqualsWithDelta(47.0, $ferries[0]['lat'], 0.01);
    }

    #[Test]
    public function aStageAwayFromTheFerryDetectsNothing(): void
    {
        $ferries = new FerryRepository($this->connection)->findNearStage([
            ['lat' => 48.0, 'lon' => 5.0],
            ['lat' => 48.1, 'lon' => 5.1],
        ], self::TOLERANCE);

        self::assertSame([], $ferries);
    }

    #[Test]
    public function aDegenerateStageDetectsNothing(): void
    {
        $repository = new FerryRepository($this->connection);

        self::assertSame([], $repository->findNearStage([], self::TOLERANCE));
        self::assertSame([], $repository->findNearStage([['lat' => 47.0, 'lon' => 2.05]], self::TOLERANCE));
    }
}
