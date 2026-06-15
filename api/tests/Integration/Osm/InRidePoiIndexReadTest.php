<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\InRide\InRidePoiRepository;
use App\InRide\PoiSuggestion;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first in-ride read layer (ADR-040): each
 * in-ride intent category maps to its osm.* table around the rider position,
 * with raw tags decoded — replacing the runtime Overpass in-ride scan.
 */
final class InRidePoiIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        $this->connection->executeStatement('TRUNCATE osm.water_points, osm.accommodations, osm.pois, osm.bike_shops');

        // One feature per category at (49.61, 6.14), plus decoys that the category
        // mapping must exclude (a hotel for shelter, a viewpoint for food).
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.water_points (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 1, 'Fontaine', 'drinking_water', '{"name":"Fontaine"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326));
            INSERT INTO osm.accommodations (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 2, 'Abri', 'shelter', '{"name":"Abri"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 3, 'Hotel', 'hotel', '{"name":"Hotel"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326));
            INSERT INTO osm.pois (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 4, 'Resto', 'restaurant', '{"name":"Resto","opening_hours":"24/7"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 5, 'Belvedere', 'viewpoint', '{"name":"Belvedere"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326));
            INSERT INTO osm.bike_shops (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 6, 'Cycles', 'bicycle', '{"name":"Cycles"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326));
            SQL);
    }

    #[Test]
    public function findNearbyMapsEachCategoryToItsTableWithDecodedTags(): void
    {
        $repository = new InRidePoiRepository($this->connection);

        $water = $repository->findNearby(49.61, 6.14, 2000, PoiSuggestion::CATEGORY_WATER);
        self::assertCount(1, $water);
        self::assertSame('Fontaine', $water[0]['tags']['name']);
        self::assertEqualsWithDelta(49.61, $water[0]['lat'], 0.0001);

        // Shelter reads osm.accommodations filtered to category 'shelter' — the hotel is excluded.
        $shelter = $repository->findNearby(49.61, 6.14, 2000, PoiSuggestion::CATEGORY_SHELTER);
        self::assertCount(1, $shelter);
        self::assertSame('Abri', $shelter[0]['tags']['name']);

        // Food reads osm.pois filtered to restaurant/cafe/fast_food — the viewpoint is excluded.
        $food = $repository->findNearby(49.61, 6.14, 2000, PoiSuggestion::CATEGORY_FOOD);
        self::assertCount(1, $food);
        self::assertSame('24/7', $food[0]['tags']['opening_hours']);

        $mechanic = $repository->findNearby(49.61, 6.14, 2000, PoiSuggestion::CATEGORY_MECHANIC);
        self::assertCount(1, $mechanic);
        self::assertSame('Cycles', $mechanic[0]['tags']['name']);
    }

    #[Test]
    public function findNearbyExcludesFeaturesOutsideTheRadius(): void
    {
        $repository = new InRidePoiRepository($this->connection);

        // ~130 km from the seeded features → nothing in a 2 km radius.
        self::assertSame([], $repository->findNearby(48.0, 2.0, 2000, PoiSuggestion::CATEGORY_WATER));
    }
}
