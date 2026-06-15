<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\Osm\CulturalPoiRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first cultural-POI read layer (ADR-040):
 * exercises the real PostGIS osm.cultural_pois table with seeded rows and asserts
 * the ST_DWithin corridor filtering plus the wikidata column mapping.
 */
final class CulturalPoiIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        $this->connection->executeStatement('TRUNCATE osm.cultural_pois');

        // A museum (with wikidata) and a castle on the corridor; a far museum (~50 km).
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.cultural_pois (osm_type, osm_id, name, category, wikidata, tags, geom) VALUES
              ('n', 1, 'Louvre', 'museum', 'Q19675', '{}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 2, 'Château', 'castle', NULL, '{}'::jsonb, ST_SetSRID(ST_MakePoint(6.145, 49.615), 4326)),
              ('n', 3, 'Musée Lointain', 'museum', NULL, '{}'::jsonb, ST_SetSRID(ST_MakePoint(6.80, 49.90), 4326))
            SQL);
    }

    #[Test]
    public function findInCorridorReturnsCulturalPoisWithWikidata(): void
    {
        $pois = new CulturalPoiRepository($this->connection)->findInCorridor([
            ['lat' => 49.60, 'lon' => 6.13],
            ['lat' => 49.62, 'lon' => 6.15],
        ], 2000);

        $byName = [];
        foreach ($pois as $poi) {
            $byName[(string) $poi['name']] = $poi;
        }

        // The far museum is excluded by ST_DWithin.
        self::assertCount(2, $pois);
        self::assertSame('museum', $byName['Louvre']['category']);
        self::assertSame('Q19675', $byName['Louvre']['wikidata']);
        self::assertSame('castle', $byName['Château']['category']);
        self::assertNull($byName['Château']['wikidata']);
        self::assertArrayNotHasKey('Musée Lointain', $byName);
    }

    #[Test]
    public function emptyRouteYieldsNoQuery(): void
    {
        self::assertSame([], new CulturalPoiRepository($this->connection)->findInCorridor([], 2000));
    }
}
