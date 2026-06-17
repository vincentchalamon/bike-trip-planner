<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\Osm\FordRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first ford read layer (ADR-040): seeds a
 * real PostGIS ford point in osm.fords and asserts the proximity detection the
 * ford alert relies on (a stage whose route passes close to the ford).
 */
final class FordIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    private const int TOLERANCE = 25;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        // A ford at (lat 47.0, lon 2.05), on the line a stage along lat 47 follows.
        $this->connection->executeStatement('TRUNCATE osm.fords');
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.fords (osm_type, osm_id, name, tags, geom) VALUES
              ('N', 1, 'Gué de Test', '{}'::jsonb, ST_SetSRID(ST_MakePoint(2.05, 47.0), 4326))
            SQL);
    }

    #[Test]
    public function aStageCrossingTheFordDetectsIt(): void
    {
        $fords = new FordRepository($this->connection)->findNearStage([
            ['lat' => 47.0, 'lon' => 2.0],
            ['lat' => 47.0, 'lon' => 2.1],
        ], self::TOLERANCE);

        self::assertCount(1, $fords);
        self::assertSame('Gué de Test', $fords[0]['name']);
        self::assertEqualsWithDelta(2.05, $fords[0]['lon'], 0.001);
    }

    #[Test]
    public function aStageAwayFromTheFordDetectsNothing(): void
    {
        $fords = new FordRepository($this->connection)->findNearStage([
            ['lat' => 48.0, 'lon' => 5.0],
            ['lat' => 48.1, 'lon' => 5.1],
        ], self::TOLERANCE);

        self::assertSame([], $fords);
    }

    #[Test]
    public function aDegenerateStageDetectsNothing(): void
    {
        $repository = new FordRepository($this->connection);

        self::assertSame([], $repository->findNearStage([], self::TOLERANCE));
        self::assertSame([], $repository->findNearStage([['lat' => 47.0, 'lon' => 2.05]], self::TOLERANCE));
    }
}
