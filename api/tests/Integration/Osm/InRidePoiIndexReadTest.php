<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\InRide\InRidePoiCategory;
use App\InRide\InRidePoiRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first in-ride read layer (ADR-040): each of
 * the eight in-ride intent categories maps to its osm.* table around the rider
 * position, with the SQL-side filters (name, shelter furniture, resupply
 * whitelist, e-bike sockets) applied — replacing the runtime Overpass in-ride
 * scan.
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

        $this->connection->executeStatement(
            'TRUNCATE osm.water_points, osm.accommodations, osm.pois, osm.bike_shops, '
            .'osm.health_services, osm.railway_stations, osm.charging_stations'
        );

        // One actionable feature per bucket at (49.61, 6.14), plus the decoys each
        // filter must reject. All at the same point so only the category mapping and
        // the SQL predicates decide what comes back, not the distance.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.water_points (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 1, 'Fontaine', 'drinking_water', '{"name":"Fontaine","opening_hours":"24/7"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326));

            INSERT INTO osm.accommodations (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 10, 'Abribus', 'shelter', '{"name":"Abribus","shelter_type":"public_transport"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 11, NULL, 'shelter', '{"shelter_type":"public_transport"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 12, 'Chariots', 'shelter', '{"name":"Chariots","shelter_type":"shopping_cart"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 13, 'Carport', 'shelter', '{"name":"Carport","shelter_type":"carport"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 14, 'Hotel', 'hotel', '{"name":"Hotel"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326));

            INSERT INTO osm.pois (osm_type, osm_id, name, category, opening_hours, tags, geom) VALUES
              ('n', 20, 'Resto', 'restaurant', 'Mo-Su 12:00-14:00', '{"name":"Resto","opening_hours":"Mo-Su 12:00-14:00"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 21, 'Super', 'supermarket', NULL, '{"name":"Super"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 22, 'Station-service', 'fuel', NULL, '{"name":"Station-service"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 30, 'Pharmacie', 'pharmacy', NULL, '{"name":"Pharmacie"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326));

            INSERT INTO osm.bike_shops (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 40, 'Cycles', 'bicycle', '{"name":"Cycles"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326));

            INSERT INTO osm.health_services (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 30, 'Pharmacie', 'pharmacy', '{"name":"Pharmacie","opening_hours":"Mo-Sa 09:00-19:00"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326));

            INSERT INTO osm.railway_stations (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 50, 'Gare', 'station', '{"name":"Gare"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326));

            INSERT INTO osm.charging_stations (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 60, 'Borne VAE', 'charging_station', '{"name":"Borne VAE","bicycle":"yes"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 61, 'Borne voiture', 'charging_station', '{"name":"Borne voiture","socket:chademo":"1"}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326));
            SQL);
    }

    #[Test]
    public function eachBucketReadsItsTable(): void
    {
        $repository = new InRidePoiRepository($this->connection);

        $water = $repository->findNearby(49.61, 6.14, 5000, InRidePoiCategory::WATER);
        self::assertCount(1, $water);
        self::assertSame('Fontaine', $water[0]['name']);
        // opening_hours has no column on water_points -> read from tags->>'opening_hours'.
        self::assertSame('24/7', $water[0]['openingHours']);
        self::assertSame('n', $water[0]['osmType']);
        self::assertSame(1, $water[0]['osmId']);
        self::assertEqualsWithDelta(49.61, $water[0]['lat'], 0.0001);

        $food = $repository->findNearby(49.61, 6.14, 5000, InRidePoiCategory::FOOD);
        self::assertSame(['Resto'], array_column($food, 'name'));
        // opening_hours read from its column on osm.pois.
        self::assertSame('Mo-Su 12:00-14:00', $food[0]['openingHours']);

        $mechanic = $repository->findNearby(49.61, 6.14, 5000, InRidePoiCategory::MECHANIC);
        self::assertSame(['Cycles'], array_column($mechanic, 'name'));

        $train = $repository->findNearby(49.61, 6.14, 5000, InRidePoiCategory::TRAIN);
        self::assertSame(['Gare'], array_column($train, 'name'));
    }

    #[Test]
    public function shelterKeepsBusSheltersAndDropsStreetFurnitureWithoutRequiringAName(): void
    {
        $repository = new InRidePoiRepository($this->connection);

        $shelter = $repository->findNearby(49.61, 6.14, 5000, InRidePoiCategory::SHELTER);
        $names = array_column($shelter, 'name');

        // public_transport is kept (named bus shelter + the unnamed one), the hotel is
        // not a shelter, and carport/shopping_cart street furniture is excluded.
        self::assertCount(2, $shelter);
        self::assertContains('Abribus', $names);
        self::assertContains(null, $names, 'an unnamed shelter must not be dropped');
        self::assertNotContains('Carport', $names);
        self::assertNotContains('Chariots', $names);
        self::assertNotContains('Hotel', $names);

        foreach ($shelter as $row) {
            self::assertSame('public_transport', $row['tags']['shelter_type']);
        }
    }

    #[Test]
    public function resupplyExcludesFuelAndPharmacy(): void
    {
        $repository = new InRidePoiRepository($this->connection);

        $resupply = $repository->findNearby(49.61, 6.14, 5000, InRidePoiCategory::RESUPPLY);

        self::assertSame(['Super'], array_column($resupply, 'name'));
    }

    #[Test]
    public function pharmacyIndexedInPoisAndHealthServicesSurfacesOncePerSearch(): void
    {
        $repository = new InRidePoiRepository($this->connection);

        // Resupply reads osm.pois, whose whitelist leaves out pharmacy: 0 occurrence.
        $resupplyNames = array_column($repository->findNearby(49.61, 6.14, 5000, InRidePoiCategory::RESUPPLY), 'name');
        self::assertNotContains('Pharmacie', $resupplyNames);

        // Health reads osm.health_services: the pharmacy appears exactly once, never
        // duplicated by its osm.pois twin (same osm_type/osm_id).
        $health = $repository->findNearby(49.61, 6.14, 5000, InRidePoiCategory::HEALTH);
        self::assertSame(['Pharmacie'], array_column($health, 'name'));
        self::assertSame('Mo-Sa 09:00-19:00', $health[0]['openingHours']);
    }

    #[Test]
    public function chargingKeepsOnlyBikeUsablePosts(): void
    {
        $repository = new InRidePoiRepository($this->connection);

        $charging = $repository->findNearby(49.61, 6.14, 5000, InRidePoiCategory::CHARGING);

        // The unqualified car charger (socket:chademo, no bicycle=yes) is dropped.
        self::assertSame(['Borne VAE'], array_column($charging, 'name'));
    }

    #[Test]
    public function findNearbyExcludesFeaturesOutsideTheRadius(): void
    {
        $repository = new InRidePoiRepository($this->connection);

        // ~130 km from the seeded features -> nothing in a 1 km radius.
        self::assertSame([], $repository->findNearby(48.0, 2.0, 1000, InRidePoiCategory::WATER));
    }
}
