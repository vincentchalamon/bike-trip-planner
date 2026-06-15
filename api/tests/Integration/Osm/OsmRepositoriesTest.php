<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\Osm\AccommodationRepository;
use App\Osm\BikeShopRepository;
use App\Osm\HealthServiceRepository;
use App\Osm\PoiRepository;
use App\Osm\RailwayStationRepository;
use App\Osm\WaterPointRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first Tier-1 read layer (ADR-040): exercises
 * the real PostGIS `osm` schema with seeded rows and asserts the ST_DWithin
 * corridor / radius / category filtering for each repository.
 */
final class OsmRepositoriesTest extends KernelTestCase
{
    use ResetDatabase;

    private Connection $connection;

    private int $osmId = 0;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        // osm.* are not Doctrine entities, so the reset does not clear them.
        $this->connection->executeStatement('TRUNCATE osm.pois, osm.accommodations, osm.water_points, osm.bike_shops, osm.health_services, osm.railway_stations');
    }

    #[Test]
    public function poiRepositoryReturnsOnlyPoisWithinTheCorridor(): void
    {
        $this->seedPoi('restaurant', 49.61, 6.14, 'On Route');
        $this->seedPoi('bakery', 49.80, 6.50, 'Far Away');

        $pois = new PoiRepository($this->connection)->findInCorridor([
            ['lat' => 49.60, 'lon' => 6.13],
            ['lat' => 49.62, 'lon' => 6.15],
        ], 2000);

        self::assertCount(1, $pois);
        self::assertSame('On Route', $pois[0]['name']);
        self::assertSame('restaurant', $pois[0]['category']);
        self::assertEqualsWithDelta(49.61, $pois[0]['lat'], 0.0001);
        self::assertEqualsWithDelta(6.14, $pois[0]['lon'], 0.0001);
    }

    #[Test]
    public function waterPointRepositoryReturnsPotablePointsWithinTheCorridor(): void
    {
        $this->seedWaterPoint('drinking_water', 49.61, 6.14);
        $this->seedWaterPoint('water_tap', 49.615, 6.145);  // potable, different category, in corridor
        $this->seedWaterPoint('spring', 49.80, 6.50);       // out of corridor

        $waterPoints = new WaterPointRepository($this->connection)->findInCorridor([
            ['lat' => 49.60, 'lon' => 6.13],
            ['lat' => 49.62, 'lon' => 6.15],
        ], 2000);

        // Both potable categories in the corridor are returned (no category filter);
        // the out-of-corridor spring is excluded by ST_DWithin.
        $categories = array_map(static fn (array $point): string => $point['category'], $waterPoints);
        self::assertCount(2, $waterPoints);
        self::assertContains('drinking_water', $categories);
        self::assertContains('water_tap', $categories);
    }

    #[Test]
    public function accommodationRepositoryFiltersByRadiusAndCategory(): void
    {
        $this->seedAccommodation('hotel', 49.611, 6.141, 'Near Hotel', 3);
        $this->seedAccommodation('camp_site', 49.611, 6.141, 'Near Campsite');
        $this->seedAccommodation('hotel', 49.90, 6.80, 'Far Hotel');

        $accommodations = new AccommodationRepository($this->connection)->findNear([
            ['lat' => 49.61, 'lon' => 6.14],
        ], 5000, ['hotel']);

        // Only the near hotel: the far one is out of radius, the campsite is filtered out.
        self::assertCount(1, $accommodations);
        self::assertSame('Near Hotel', $accommodations[0]['name']);
        self::assertSame('hotel', $accommodations[0]['category']);
        self::assertSame(3, $accommodations[0]['stars']);
    }

    #[Test]
    public function bikeShopRepositoryDerivesRepairFlagAndFiltersByCorridor(): void
    {
        $this->seedBikeShop('bicycle', 49.61, 6.14, 'Repair Shop', '{"shop":"bicycle","service:bicycle:repair":"yes"}');
        $this->seedBikeShop('bicycle', 49.615, 6.145, 'Sale Only', '{"shop":"bicycle"}');
        $this->seedBikeShop('repair_station', 49.612, 6.142, 'Workshop', '{"service:bicycle:repair":"yes"}');
        $this->seedBikeShop('bicycle', 49.90, 6.80, 'Far Shop', '{"shop":"bicycle","service:bicycle:repair":"yes"}');

        $shops = new BikeShopRepository($this->connection)->findInCorridor([
            ['lat' => 49.60, 'lon' => 6.13],
            ['lat' => 49.62, 'lon' => 6.15],
        ], 2000);

        // Three shops in the corridor; the far one is excluded by ST_DWithin.
        $repairByName = [];
        foreach ($shops as $shop) {
            $repairByName[(string) $shop['name']] = $shop['hasRepair'];
        }

        self::assertCount(3, $shops);
        self::assertTrue($repairByName['Repair Shop'], 'shop=bicycle with service:bicycle:repair=yes is a repair shop');
        self::assertFalse($repairByName['Sale Only'], 'shop=bicycle without the repair tag is sale-only');
        self::assertTrue($repairByName['Workshop'], 'a repair workshop without shop=bicycle still has repair');
        self::assertArrayNotHasKey('Far Shop', $repairByName);
    }

    #[Test]
    public function healthServiceRepositoryReturnsServicesWithinTheCorridor(): void
    {
        $this->seedHealthService('pharmacy', 49.61, 6.14, 'On Route Pharmacy');
        $this->seedHealthService('clinic', 49.615, 6.145, 'On Route Clinic');
        $this->seedHealthService('hospital', 49.90, 6.80, 'Far Hospital');

        $services = new HealthServiceRepository($this->connection)->findInCorridor([
            ['lat' => 49.60, 'lon' => 6.13],
            ['lat' => 49.62, 'lon' => 6.15],
        ], 2000);

        // Both in-corridor services are returned (no category filter); the far
        // hospital is excluded by ST_DWithin.
        $categories = array_map(static fn (array $service): string => $service['category'], $services);
        self::assertCount(2, $services);
        self::assertContains('pharmacy', $categories);
        self::assertContains('clinic', $categories);
    }

    #[Test]
    public function railwayStationRepositoryReturnsStationsWithinTheCorridor(): void
    {
        $this->seedRailwayStation(49.61, 6.14, 'On Route Station');
        $this->seedRailwayStation(49.90, 6.80, 'Far Station');

        $stations = new RailwayStationRepository($this->connection)->findInCorridor([
            ['lat' => 49.60, 'lon' => 6.13],
            ['lat' => 49.62, 'lon' => 6.15],
        ], 10000);

        // The far station (~50 km) is excluded by ST_DWithin.
        self::assertCount(1, $stations);
        self::assertSame('On Route Station', $stations[0]['name']);
        self::assertSame('station', $stations[0]['category']);
    }

    #[Test]
    public function emptyRouteOrCategoriesYieldNoQuery(): void
    {
        $this->seedPoi('restaurant', 49.61, 6.14);

        self::assertSame([], new PoiRepository($this->connection)->findInCorridor([], 2000));
        self::assertSame([], new WaterPointRepository($this->connection)->findInCorridor([], 2000));
        self::assertSame([], new BikeShopRepository($this->connection)->findInCorridor([], 2000));
        self::assertSame([], new HealthServiceRepository($this->connection)->findInCorridor([], 2000));
        self::assertSame([], new RailwayStationRepository($this->connection)->findInCorridor([], 10000));
        self::assertSame([], new AccommodationRepository($this->connection)->findNear([['lat' => 49.61, 'lon' => 6.14]], 5000, []));
        self::assertSame([], new AccommodationRepository($this->connection)->findNear([], 5000, ['hotel']));
    }

    private function seedPoi(string $category, float $lat, float $lon, ?string $name = null): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO osm.pois (osm_type, osm_id, name, category, tags, geom)
                VALUES ('n', :id, :name, :category, '{}'::jsonb, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
                SQL,
            ['id' => ++$this->osmId, 'name' => $name, 'category' => $category, 'lon' => $lon, 'lat' => $lat],
        );
    }

    private function seedWaterPoint(string $category, float $lat, float $lon): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO osm.water_points (osm_type, osm_id, name, category, tags, geom)
                VALUES ('n', :id, NULL, :category, '{}'::jsonb, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
                SQL,
            ['id' => ++$this->osmId, 'category' => $category, 'lon' => $lon, 'lat' => $lat],
        );
    }

    private function seedAccommodation(string $category, float $lat, float $lon, ?string $name = null, ?int $stars = null): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO osm.accommodations (osm_type, osm_id, name, category, stars, tags, geom)
                VALUES ('n', :id, :name, :category, :stars, '{}'::jsonb, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
                SQL,
            ['id' => ++$this->osmId, 'name' => $name, 'category' => $category, 'stars' => $stars, 'lon' => $lon, 'lat' => $lat],
        );
    }

    private function seedBikeShop(string $category, float $lat, float $lon, ?string $name, string $tagsJson): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO osm.bike_shops (osm_type, osm_id, name, category, tags, geom)
                VALUES ('n', :id, :name, :category, CAST(:tags AS jsonb), ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
                SQL,
            ['id' => ++$this->osmId, 'name' => $name, 'category' => $category, 'tags' => $tagsJson, 'lon' => $lon, 'lat' => $lat],
        );
    }

    private function seedHealthService(string $category, float $lat, float $lon, ?string $name): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO osm.health_services (osm_type, osm_id, name, category, tags, geom)
                VALUES ('n', :id, :name, :category, '{}'::jsonb, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
                SQL,
            ['id' => ++$this->osmId, 'name' => $name, 'category' => $category, 'lon' => $lon, 'lat' => $lat],
        );
    }

    private function seedRailwayStation(float $lat, float $lon, ?string $name): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO osm.railway_stations (osm_type, osm_id, name, category, tags, geom)
                VALUES ('n', :id, :name, 'station', '{}'::jsonb, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
                SQL,
            ['id' => ++$this->osmId, 'name' => $name, 'lon' => $lon, 'lat' => $lat],
        );
    }
}
