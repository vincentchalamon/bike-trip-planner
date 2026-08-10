<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tourism;

use App\Tourism\AccommodationRepository;
use App\Tourism\CulturalPoiRepository;
use App\Tourism\EventRepository;
use App\Tourism\FoodPoiRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first DataTourisme read layer (ADR-040):
 * seeds real rows in the tourism schema and asserts the corridor / radius /
 * date filtering each repository performs against PostGIS.
 */
final class TourismIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        $this->connection->executeStatement('TRUNCATE tourism.cultural_pois, tourism.food_pois, tourism.accommodations, tourism.events');

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO tourism.food_pois (id, name, category, opening_hours, description, wikidata, tags, geom) VALUES
              ('f1', 'Boulangerie du Lac', 'bakery', NULL, NULL, NULL, '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('f2', 'Far Restaurant', 'restaurant', NULL, NULL, NULL, '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(6.80, 50.90), 4326))
            SQL);

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO tourism.cultural_pois (id, name, category, opening_hours, description, website, wikidata, image_url, wikipedia_url, tags, geom) VALUES
              ('c1', 'Musée du Lac', 'museum', 'Mo-Fr 09:00-17:00', 'Un musée.', 'https://musee.test', 'Q42', 'https://img.test/musee.jpg', 'https://fr.wikipedia.org/wiki/Musee', '{"type": ["CulturalSite"], "city": "Nancy"}'::jsonb,
                  ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('c2', 'Far Museum', 'museum', NULL, NULL, NULL, NULL, NULL, NULL, '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(6.80, 50.90), 4326))
            SQL);

        // The contact / Wikidata columns exist since #872; `tags` still carries the
        // keys with no column of their own (booking_url, image_url, labels).
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO tourism.accommodations (id, name, category, capacity, price, description, opening_hours, website, phone, wikidata, image_url, wikipedia_url, tags, geom) VALUES
              ('a1', 'Gîte du Lac', 'chalet', 4, 75.00, NULL, 'Apr-Oct', 'https://gite.test', '+33 3 88 00 00 00', 'Q1234',
                  'https://img.test/gite.jpg', 'https://fr.wikipedia.org/wiki/Gite',
                  '{"type": ["Accommodation", "RentalAccommodation"], "website": "https://gite.test", "opening_hours": "Apr-Oct", "labels": ["Accueil Vélo"]}'::jsonb,
                  ST_SetSRID(ST_MakePoint(2.50, 48.50), 4326)),
              ('a2', 'Grand Hôtel', 'hotel', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(2.50, 48.50), 4326))
            SQL);

        // `source` is omitted on purpose: the column defaults to 'datatourisme'
        // (Version20260810120000), which the read layer must surface.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO tourism.events (id, name, category, start_date, end_date, url, description, price_min, tags, geom) VALUES
              ('e1', 'Festival', 'festival', '2026-07-01', '2026-07-05', 'https://ex.test', 'Desc', 12.5, '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(5.00, 45.00), 4326)),
              ('e2', 'Past Event', 'concert', '2026-06-01', '2026-06-02', NULL, NULL, NULL, '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(5.00, 45.00), 4326)),
              ('e3', 'Linkless Event', 'festival', '2026-07-01', '2026-07-05', '', NULL, NULL, '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(5.00, 45.00), 4326))
            SQL);
    }

    #[Test]
    public function culturalPoisAreFilteredByCorridor(): void
    {
        $pois = new CulturalPoiRepository($this->connection)->findInCorridor([
            ['lat' => 49.60, 'lon' => 6.13],
            ['lat' => 49.62, 'lon' => 6.15],
        ], 5000);

        self::assertCount(1, $pois, 'the far museum is excluded by ST_DWithin');
        self::assertSame('Musée du Lac', $pois[0]['name']);
        self::assertSame('Mo-Fr 09:00-17:00', $pois[0]['openingHours']);
        self::assertSame('Un musée.', $pois[0]['description']);
        self::assertSame('Q42', $pois[0]['wikidata']);
        // Provisioner-enriched columns are surfaced by the read layer (ADR-041).
        self::assertSame('https://img.test/musee.jpg', $pois[0]['imageUrl']);
        self::assertSame('https://fr.wikipedia.org/wiki/Musee', $pois[0]['wikipediaUrl']);
    }

    #[Test]
    public function foodPoisAreFilteredByCorridor(): void
    {
        $pois = new FoodPoiRepository($this->connection)->findInCorridor([
            ['lat' => 49.60, 'lon' => 6.13],
            ['lat' => 49.62, 'lon' => 6.15],
        ], 5000);

        self::assertCount(1, $pois, 'the far restaurant is excluded by ST_DWithin');
        self::assertSame('Boulangerie du Lac', $pois[0]['name']);
        self::assertSame('bakery', $pois[0]['category']);
    }

    #[Test]
    public function accommodationsAreFilteredByCategoryAndRadius(): void
    {
        $accommodations = new AccommodationRepository($this->connection)->findNear(
            [['lat' => 48.50, 'lon' => 2.50]],
            5000,
            ['chalet'],
        );

        self::assertCount(1, $accommodations, 'only the requested category is returned');
        self::assertSame('Gîte du Lac', $accommodations[0]['name']);
        self::assertSame(4, $accommodations[0]['capacity']);
        self::assertSame(75.0, $accommodations[0]['price']);
        // The columns the migration added (#872): without them the read layer could
        // expose neither a link nor a Q-ID for any of the 124k curated lodgings.
        self::assertSame('https://gite.test', $accommodations[0]['website']);
        self::assertSame('+33 3 88 00 00 00', $accommodations[0]['phone']);
        self::assertSame('Apr-Oct', $accommodations[0]['openingHours']);
        self::assertSame('Q1234', $accommodations[0]['wikidata']);
        self::assertSame('https://img.test/gite.jpg', $accommodations[0]['imageUrl']);
        self::assertSame('https://fr.wikipedia.org/wiki/Gite', $accommodations[0]['wikipediaUrl']);
        // The preserved flux keys reach the source flattened to the OSM-tag contract:
        // scalars only, so the list-valued `type` / `labels` stay in the jsonb (#871).
        self::assertSame(
            ['website' => 'https://gite.test', 'opening_hours' => 'Apr-Oct'],
            $accommodations[0]['tags'],
        );

        $bare = new AccommodationRepository($this->connection)->findNear(
            [['lat' => 48.50, 'lon' => 2.50]],
            5000,
            ['hotel'],
        );
        self::assertSame('Grand Hôtel', $bare[0]['name']);
        self::assertNull($bare[0]['website']);
        self::assertNull($bare[0]['wikidata']);
    }

    #[Test]
    public function accommodationsKeepTheNearestRowsOnlyUpToTheLimit(): void
    {
        $this->seedFortyGitesFarthestFirst();

        $rows = new AccommodationRepository($this->connection)->findNear(
            [['lat' => 48.50, 'lon' => 2.50]],
            5000,
            ['chalet'],
        );

        // MAX_ROWS_PER_POINT = 30 per end point, and the KNN order means the cap
        // keeps the closest rows: Gîte 30..40 are dropped, not an arbitrary slice
        // of the 41 in range (what the flat `LIMIT 200` did on a real trip).
        $expected = array_merge(['Gîte du Lac'], array_map(
            static fn (int $i): string => \sprintf('Gîte %02d', $i),
            range(1, 29),
        ));

        self::assertSame($expected, array_column($rows, 'name'));
    }

    #[Test]
    public function accommodationsAreReturnedInTheSameOrderOnEveryRun(): void
    {
        $this->seedFortyGitesFarthestFirst();

        // Three rows share the end point geom (the setUp gîte and hotel plus this
        // one, inserted last with the lowest id): the distance tie is resolved on
        // the DataTourisme id, not on the physical row order.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO tourism.accommodations (id, name, category, capacity, price, description, tags, geom) VALUES
              ('a0', 'Auberge Zéro', 'hotel', NULL, NULL, NULL, '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(2.50, 48.50), 4326))
            SQL);

        $repository = new AccommodationRepository($this->connection);
        $points = [['lat' => 48.50, 'lon' => 2.50]];

        $first = $repository->findNear($points, 5000, ['chalet', 'hotel']);
        $second = $repository->findNear($points, 5000, ['chalet', 'hotel']);

        self::assertSame($first, $second, 'the same scan must return the same rows in the same order (ADR-040)');
        self::assertCount(30, $first);
        self::assertSame(
            ['Auberge Zéro', 'Gîte du Lac', 'Grand Hôtel'],
            \array_slice(array_column($first, 'name'), 0, 3),
            'co-located rows are ordered by id: a0 < a1 < a2',
        );
    }

    #[Test]
    public function accommodationsGiveEveryEndPointItsOwnBudget(): void
    {
        // A dense end point (48.50 2.50): 80 gîtes 22 m apart, so 60 of them sit
        // closer than anything around the isolated end point below.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO tourism.accommodations (id, name, category, capacity, price, description, tags, geom)
            SELECT 'g' || lpad(i::text, 2, '0'), 'Gîte ' || lpad(i::text, 2, '0'), 'chalet', NULL, NULL, NULL, '{}'::jsonb,
                   ST_SetSRID(ST_MakePoint(2.50, 48.50 + i * 0.0002), 4326)
            FROM generate_series(80, 1, -1) AS i
            SQL);

        // An isolated end point (49.52 3.50), ~110 km away: three gîtes 2.2 to
        // 3.9 km out, all inside the radius but all farther than the dense cluster.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO tourism.accommodations (id, name, category, capacity, price, description, tags, geom) VALUES
              ('i1', 'Gîte Isolé 1', 'chalet', NULL, NULL, NULL, '{}'::jsonb, ST_SetSRID(ST_MakePoint(3.50, 49.50), 4326)),
              ('i2', 'Gîte Isolé 2', 'chalet', NULL, NULL, NULL, '{}'::jsonb, ST_SetSRID(ST_MakePoint(3.50, 49.55), 4326)),
              ('i3', 'Gîte Isolé 3', 'chalet', NULL, NULL, NULL, '{}'::jsonb, ST_SetSRID(ST_MakePoint(3.50, 49.555), 4326))
            SQL);

        $rows = new AccommodationRepository($this->connection)->findNear(
            [['lat' => 48.50, 'lon' => 2.50], ['lat' => 49.52, 'lon' => 3.50]],
            5000,
            ['chalet'],
        );

        // A single top-N over the combined multipoint (the flat `LIMIT 200` on a
        // long trip) would spend the whole budget on the dense cluster and return
        // nothing for the isolated stage. The cap is per end point: 30 + 3.
        self::assertCount(33, $rows);
        self::assertSame(
            ['Gîte Isolé 1', 'Gîte Isolé 2', 'Gîte Isolé 3'],
            \array_slice(array_column($rows, 'name'), 30),
            'the isolated end point keeps its own candidates, nearest first',
        );
    }

    #[Test]
    public function accommodationsAssignBisectorRowsWithTheSameMetricAsTheDistributor(): void
    {
        // At latitude 60 a degree of longitude is only cos(60) = half a degree of
        // latitude. 'Gîte Bissectrice' (60.00 0.00) is 0.10 deg south of end point A
        // but 0.12 deg west of end point B: planar degrees call A nearer
        // (0.10 < 0.12), metres call B nearer (6.7 km < 11.1 km), and metres are what
        // GeometryBasedDistributor::distributeByEndpoint uses. A's budget is filled
        // with 30 gîtes within 100 m, so a row assigned to A is ranked 31st and
        // dropped — surviving proves it went to B.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO tourism.accommodations (id, name, category, capacity, price, description, tags, geom)
            SELECT 'd' || lpad(i::text, 2, '0'), 'Gîte Dense ' || lpad(i::text, 2, '0'), 'chalet', NULL, NULL, NULL, '{}'::jsonb,
                   ST_SetSRID(ST_MakePoint(0.0, 60.10 + i * 0.00001), 4326)
            FROM generate_series(1, 30) AS i
            SQL);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO tourism.accommodations (id, name, category, capacity, price, description, tags, geom) VALUES
              ('zz', 'Gîte Bissectrice', 'chalet', NULL, NULL, NULL, '{}'::jsonb, ST_SetSRID(ST_MakePoint(0.0, 60.00), 4326))
            SQL);

        $rows = new AccommodationRepository($this->connection)->findNear(
            [['lat' => 60.10, 'lon' => 0.0], ['lat' => 60.00, 'lon' => 0.12]],
            15000,
            ['chalet'],
        );

        self::assertContains(
            'Gîte Bissectrice',
            array_column($rows, 'name'),
            'a bisector row must be assigned to the end point the distributor will pick (metres), not the planar-degree one',
        );
        self::assertCount(31, $rows);
    }

    /**
     * 40 extra gîtes, 111 m apart along the meridian, all inside the 5 km
     * radius (41 rows in range with the setUp gîte, for a 30-row cap). Inserted
     * farthest first so the physical row order reverses the expected KNN order.
     */
    private function seedFortyGitesFarthestFirst(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO tourism.accommodations (id, name, category, capacity, price, description, tags, geom)
            SELECT 'g' || lpad(i::text, 2, '0'), 'Gîte ' || lpad(i::text, 2, '0'), 'chalet', NULL, NULL, NULL, '{}'::jsonb,
                   ST_SetSRID(ST_MakePoint(2.50, 48.50 + i * 0.001), 4326)
            FROM generate_series(40, 1, -1) AS i
            SQL);
    }

    #[Test]
    public function eventsAreFilteredByDateAndRadius(): void
    {
        $repository = new EventRepository($this->connection);

        $active = $repository->findActiveNear(45.00, 5.00, 20000, '2026-07-03');
        // The linkless event (e3) is active on the date and in range but excluded:
        // an event a rider cannot open is noise (issue #975).
        self::assertCount(1, $active, 'only the active, linked event is returned');
        self::assertSame('Festival', $active[0]['name']);
        self::assertSame('2026-07-01', $active[0]['startDate']);
        self::assertSame('2026-07-05', $active[0]['endDate']);
        self::assertSame(12.5, $active[0]['priceMin']);
        // The source column is populated (defaulted here) and surfaced.
        self::assertSame('datatourisme', $active[0]['source']);

        // A date outside every event's range yields nothing.
        self::assertSame([], $repository->findActiveNear(45.00, 5.00, 20000, '2026-08-01'));
    }
}
