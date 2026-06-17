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

        // A museum (with wikidata + provisioner-enriched columns) and a castle on
        // the corridor; a far museum (~50 km).
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.cultural_pois (osm_type, osm_id, name, category, wikidata, description, opening_hours, image_url, wikipedia_url, tags, geom) VALUES
              ('n', 1, 'Louvre', 'museum', 'Q19675', 'Art museum', '09:00-18:00', 'https://img.test/louvre.jpg', 'https://fr.wikipedia.org/wiki/Louvre', '{}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 2, 'Château', 'castle', NULL, NULL, NULL, NULL, NULL, '{}'::jsonb, ST_SetSRID(ST_MakePoint(6.145, 49.615), 4326)),
              ('n', 3, 'Musée Lointain', 'museum', NULL, NULL, NULL, NULL, NULL, '{}'::jsonb, ST_SetSRID(ST_MakePoint(6.80, 49.90), 4326))
            SQL);
    }

    #[Test]
    public function findInCorridorReturnsCulturalPoisWithWikidataAndEnrichment(): void
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
        // Provisioner-enriched columns are surfaced by the read layer (ADR-041).
        self::assertSame('Art museum', $byName['Louvre']['description']);
        self::assertSame('09:00-18:00', $byName['Louvre']['openingHours']);
        self::assertSame('https://img.test/louvre.jpg', $byName['Louvre']['imageUrl']);
        self::assertSame('https://fr.wikipedia.org/wiki/Louvre', $byName['Louvre']['wikipediaUrl']);
        self::assertSame('castle', $byName['Château']['category']);
        self::assertNull($byName['Château']['wikidata']);
        self::assertNull($byName['Château']['imageUrl']);
        self::assertArrayNotHasKey('Musée Lointain', $byName);
    }

    #[Test]
    public function emptyRouteYieldsNoQuery(): void
    {
        self::assertSame([], new CulturalPoiRepository($this->connection)->findInCorridor([], 2000));
    }
}
