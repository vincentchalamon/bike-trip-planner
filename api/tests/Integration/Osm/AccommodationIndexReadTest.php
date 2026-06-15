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
            INSERT INTO osm.accommodations (osm_type, osm_id, name, category, website, wikidata, tags, geom) VALUES
              ('n', 8001, 'Hotel du Centre', 'hotel', 'https://hotel.example', 'Q42', '{}'::jsonb, ST_SetSRID(ST_MakePoint(2.5, 48.5), 4326)),
              ('n', 8002, 'Camping du Lac', 'camp_site', NULL, NULL, '{"charge": "18 EUR"}'::jsonb, ST_SetSRID(ST_MakePoint(2.51, 48.51), 4326)),
              ('n', 8003, 'Auberge Lointaine', 'hotel', NULL, NULL, '{}'::jsonb, ST_SetSRID(ST_MakePoint(3.5, 49.5), 4326)),
              ('n', 8004, 'Auberge de Jeunesse', 'hostel', NULL, NULL, '{}'::jsonb, ST_SetSRID(ST_MakePoint(2.5, 48.5), 4326))
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

        self::assertArrayHasKey('camp_site', $byType);
        self::assertSame(18.0, $byType['camp_site']['priceMin']);
        self::assertSame(18.0, $byType['camp_site']['priceMax']);
        self::assertTrue($byType['camp_site']['isExact']);
        self::assertNull($byType['camp_site']['url']);
        self::assertFalse($byType['camp_site']['hasWebsite']);
    }

    private function source(): OsmAccommodationSource
    {
        return new OsmAccommodationSource(
            new AccommodationRepository($this->connection),
            new PricingHeuristicEngine(),
        );
    }
}
