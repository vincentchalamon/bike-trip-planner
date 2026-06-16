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
            INSERT INTO tourism.cultural_pois (id, name, category, opening_hours, description, wikidata, tags, geom) VALUES
              ('c1', 'Musée du Lac', 'museum', 'Mo-Fr 09:00-17:00', 'Un musée.', 'Q42', '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('c2', 'Far Museum', 'museum', NULL, NULL, NULL, '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(6.80, 50.90), 4326))
            SQL);

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO tourism.accommodations (id, name, category, capacity, price, description, tags, geom) VALUES
              ('a1', 'Gîte du Lac', 'apartment', 4, 75.00, NULL, '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(2.50, 48.50), 4326)),
              ('a2', 'Grand Hôtel', 'hotel', NULL, NULL, NULL, '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(2.50, 48.50), 4326))
            SQL);

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO tourism.events (id, name, category, start_date, end_date, url, description, price_min, tags, geom) VALUES
              ('e1', 'Festival', 'festival', '2026-07-01', '2026-07-05', 'https://ex.test', 'Desc', 12.5, '{}'::jsonb,
                  ST_SetSRID(ST_MakePoint(5.00, 45.00), 4326)),
              ('e2', 'Past Event', 'concert', '2026-06-01', '2026-06-02', NULL, NULL, NULL, '{}'::jsonb,
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
            ['apartment'],
        );

        self::assertCount(1, $accommodations, 'only the requested category is returned');
        self::assertSame('Gîte du Lac', $accommodations[0]['name']);
        self::assertSame(4, $accommodations[0]['capacity']);
        self::assertSame(75.0, $accommodations[0]['price']);
    }

    #[Test]
    public function eventsAreFilteredByDateAndRadius(): void
    {
        $repository = new EventRepository($this->connection);

        $active = $repository->findActiveNear(45.00, 5.00, 20000, '2026-07-03');
        self::assertCount(1, $active, 'only the event active on the date is returned');
        self::assertSame('Festival', $active[0]['name']);
        self::assertSame('2026-07-01', $active[0]['startDate']);
        self::assertSame('2026-07-05', $active[0]['endDate']);
        self::assertSame(12.5, $active[0]['priceMin']);

        // A date outside every event's range yields nothing.
        self::assertSame([], $repository->findActiveNear(45.00, 5.00, 20000, '2026-08-01'));
    }
}
