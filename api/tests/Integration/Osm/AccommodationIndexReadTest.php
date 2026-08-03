<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\AccommodationSource\OsmAccommodationSource;
use App\ApiResource\Model\Coordinate;
use App\Engine\PricingHeuristicEngine;
use App\Osm\AccommodationRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * End-to-end coverage of the local-first accommodation cut-over (ADR-040): runs
 * the real OsmAccommodationSource against the real AccommodationRepository on a
 * PostGIS test DB seeded with committed osm fixtures, proving that accommodations
 * are detected from the index (no more empty-on-Overpass-error) with radius and
 * category filtering, and that columns/tags map onto the candidate shape (charge
 * to exact price, website to url, wikidata to wikidataId).
 */
final class AccommodationIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        // osm.* are not Doctrine entities, so the Foundry reset does not clear them.
        $this->connection->executeStatement('TRUNCATE osm.accommodations');

        // Committed SQL fixtures (ADR-040). Two accommodations within 5 km of
        // the stage end point (48.5, 2.5): a hotel (website + wikidata) and a camp
        // site (charge tag). A far hotel (~130 km) and a near hostel exercise the
        // radius and category filters respectively.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.accommodations (osm_type, osm_id, name, category, website, wikidata, description, image_url, wikipedia_url, tags, geom) VALUES
              ('n', 8001, 'Hotel du Centre', 'hotel', 'https://hotel.example', 'Q42', 'Cosy hotel', 'https://img.test/hotel.jpg', 'https://fr.wikipedia.org/wiki/Hotel', '{}'::jsonb, ST_SetSRID(ST_MakePoint(2.5, 48.5), 4326)),
              ('n', 8002, 'Camping du Lac', 'camp_site', NULL, NULL, NULL, NULL, NULL, '{"charge": "18 EUR"}'::jsonb, ST_SetSRID(ST_MakePoint(2.51, 48.51), 4326)),
              ('n', 8003, 'Auberge Lointaine', 'hotel', NULL, NULL, NULL, NULL, NULL, '{}'::jsonb, ST_SetSRID(ST_MakePoint(3.5, 49.5), 4326)),
              ('n', 8004, 'Auberge de Jeunesse', 'hostel', NULL, NULL, NULL, NULL, NULL, '{}'::jsonb, ST_SetSRID(ST_MakePoint(2.5, 48.5), 4326))
            SQL);
    }

    #[Test]
    public function fetchDetectsAccommodationsFromTheIndexWithinRadiusAndCategory(): void
    {
        $results = $this->source()->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel', 'camp_site']);

        $types = array_map(static fn (array $candidate): string => $candidate['type'], $results);
        sort($types);

        // The near hotel and camp site are detected; the far hotel (out of radius)
        // and the near hostel (category not requested) are excluded.
        self::assertSame(['camp_site', 'hotel'], $types);
    }

    #[Test]
    public function fetchMatchesAccommodationsAcrossMultipleEndpoints(): void
    {
        // A real trip passes one end point per stage: fetch() builds a MULTIPOINT of
        // N vertices. Two end points, each near a different hotel, must both match —
        // the far hotel (~130 km) is out of range of the first end point alone.
        $results = $this->source()->fetch(
            [new Coordinate(48.5, 2.5), new Coordinate(49.5, 3.5)],
            5000,
            ['hotel'],
        );

        $names = array_map(static fn (array $candidate): string => $candidate['name'], $results);
        sort($names);

        self::assertSame(['Auberge Lointaine', 'Hotel du Centre'], $names);
    }

    #[Test]
    public function fetchMapsColumnsAndTagsOntoTheCandidateShape(): void
    {
        $results = $this->source()->fetch([new Coordinate(48.5, 2.5)], 5000, ['hotel', 'camp_site']);

        $byType = [];
        foreach ($results as $candidate) {
            $byType[$candidate['type']] = $candidate;
        }

        self::assertArrayHasKey('hotel', $byType);
        self::assertSame('Hotel du Centre', $byType['hotel']['name']);
        self::assertSame('https://hotel.example', $byType['hotel']['url']);
        self::assertTrue($byType['hotel']['hasWebsite']);
        self::assertSame('Q42', $byType['hotel']['wikidataId']);
        self::assertSame('osm', $byType['hotel']['source']);
        // Provisioner-enriched Wikidata columns flow through to the candidate (ADR-041).
        self::assertSame('Cosy hotel', $byType['hotel']['description']);
        self::assertSame('https://img.test/hotel.jpg', $byType['hotel']['imageUrl']);
        self::assertSame('https://fr.wikipedia.org/wiki/Hotel', $byType['hotel']['wikipediaUrl']);

        self::assertArrayHasKey('camp_site', $byType);
        self::assertSame(18.0, $byType['camp_site']['priceMin']);
        self::assertSame(18.0, $byType['camp_site']['priceMax']);
        self::assertTrue($byType['camp_site']['isExact']);
        self::assertNull($byType['camp_site']['url']);
        self::assertFalse($byType['camp_site']['hasWebsite']);
    }

    #[Test]
    public function findNearKeepsTheNearestRowsOnlyUpToTheLimit(): void
    {
        $this->seedFortyHotelsFarthestFirst();

        $rows = new AccommodationRepository($this->connection)->findNear(
            [['lat' => 48.5, 'lon' => 2.5]],
            5000,
            ['hotel'],
        );

        // MAX_ROWS_PER_POINT = 30 per end point, and the KNN order means the 30
        // retained rows are the closest ones: Hotel 30..40 are dropped, not an
        // arbitrary slice of the 41 in range.
        $expected = array_merge(['Hotel du Centre'], array_map(
            static fn (int $i): string => \sprintf('Hotel %02d', $i),
            range(1, 29),
        ));

        self::assertSame($expected, array_column($rows, 'name'));
    }

    #[Test]
    public function findNearReturnsTheSameRowsInTheSameOrderOnEveryRun(): void
    {
        $this->seedFortyHotelsFarthestFirst();

        // Three rows share the end point geom (the setUp hotel and hostel plus
        // this one, inserted last with the lowest osm_id): the distance tie is
        // resolved on the primary key, not on the physical row order.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.accommodations (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 7999, 'Hotel Zero', 'hotel', '{}'::jsonb, ST_SetSRID(ST_MakePoint(2.5, 48.5), 4326))
            SQL);

        $repository = new AccommodationRepository($this->connection);
        $points = [['lat' => 48.5, 'lon' => 2.5]];

        $first = $repository->findNear($points, 5000, ['hotel', 'hostel']);
        $second = $repository->findNear($points, 5000, ['hotel', 'hostel']);

        self::assertSame($first, $second, 'the same scan must return the same rows in the same order (ADR-040)');
        self::assertCount(30, $first);
        self::assertSame(
            ['Hotel Zero', 'Hotel du Centre', 'Auberge de Jeunesse'],
            \array_slice(array_column($first, 'name'), 0, 3),
            'co-located rows are ordered by (osm_type, osm_id): 7999 < 8001 < 8004',
        );
    }

    #[Test]
    public function findNearGivesEveryEndPointItsOwnBudget(): void
    {
        // A dense end point (48.5 2.5): 80 hotels 22 m apart, so 60 of them sit
        // closer than anything around the isolated end point below.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.accommodations (osm_type, osm_id, name, category, tags, geom)
            SELECT 'n', 9000 + i, 'Hotel ' || lpad(i::text, 2, '0'), 'hotel', '{}'::jsonb,
                   ST_SetSRID(ST_MakePoint(2.5, 48.5 + i * 0.0002), 4326)
            FROM generate_series(80, 1, -1) AS i
            SQL);

        // An isolated end point (49.52 3.5), ~110 km away: three hotels 2.2 to
        // 3.9 km out, all inside the radius but all farther than the dense
        // cluster. The setUp 'Auberge Lointaine' (3.5 49.5) is the nearest one.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.accommodations (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 9500, 'Auberge Isolee 1', 'hotel', '{}'::jsonb, ST_SetSRID(ST_MakePoint(3.5, 49.55), 4326)),
              ('n', 9501, 'Auberge Isolee 2', 'hotel', '{}'::jsonb, ST_SetSRID(ST_MakePoint(3.5, 49.555), 4326))
            SQL);

        $rows = new AccommodationRepository($this->connection)->findNear(
            [['lat' => 48.5, 'lon' => 2.5], ['lat' => 49.52, 'lon' => 3.5]],
            5000,
            ['hotel'],
        );

        // A single top-N over the combined multipoint would spend the whole budget
        // on the dense cluster and return nothing at all for the isolated stage.
        // The cap is per end point, so both get served: 30 + 3.
        self::assertCount(33, $rows);
        self::assertSame(
            ['Auberge Lointaine', 'Auberge Isolee 1', 'Auberge Isolee 2'],
            \array_slice(array_column($rows, 'name'), 30),
            'the isolated end point keeps its own candidates, nearest first',
        );
    }

    /**
     * 40 extra hotels, 111 m apart along the meridian, all inside the 5 km radius
     * (41 rows in range with the setUp hotel, for a 30-row cap). Inserted farthest
     * first so the physical row order is the reverse of the expected KNN order.
     */
    private function seedFortyHotelsFarthestFirst(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.accommodations (osm_type, osm_id, name, category, tags, geom)
            SELECT 'n', 9000 + i, 'Hotel ' || lpad(i::text, 2, '0'), 'hotel', '{}'::jsonb,
                   ST_SetSRID(ST_MakePoint(2.5, 48.5 + i * 0.001), 4326)
            FROM generate_series(40, 1, -1) AS i
            SQL);
    }

    private function source(): OsmAccommodationSource
    {
        return new OsmAccommodationSource(
            new AccommodationRepository($this->connection),
            new PricingHeuristicEngine(),
        );
    }
}
