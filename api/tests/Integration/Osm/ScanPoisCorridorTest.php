<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\RiderTimeEstimatorInterface;
use App\Enum\AlertType;
use App\Geo\GeometryBasedDistributor;
use App\Geo\HaversineDistance;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanPois;
use App\MessageHandler\ScanPoisHandler;
use App\Osm\PoiRepository;
use App\Osm\WaterPointRepository;
use App\Poi\SupplyTimelineBuilder;
use App\Repository\TripRequestRepositoryInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * End-to-end coverage of the local-first POI/water cut-over (ADR-040): runs the
 * real ScanPoisHandler against real PoiRepository/WaterPointRepository on a
 * PostGIS test DB seeded with committed osm fixtures, proving that POIs are
 * detected from the index and that the lunch nudge fires from deterministic data
 * (no more empty-on-error false positive) — only when no resupply POI is in range.
 */
final class ScanPoisCorridorTest extends KernelTestCase
{
    use ResetDatabase;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        $this->connection->executeStatement('TRUNCATE osm.pois, osm.accommodations, osm.water_points');

        // Committed SQL fixtures (ADR-040 / #56). Corridor A (49.61, 6.14) carries a
        // resupply POI + drinking water; corridor B (50.00, 7.00) carries only a
        // non-resupply POI. The tests pick a corridor by routing a stage near A or B.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.pois (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 9001, 'Le Bistrot', 'restaurant', '{}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326)),
              ('n', 9002, 'Belvedere', 'viewpoint', '{}'::jsonb, ST_SetSRID(ST_MakePoint(7.00, 50.00), 4326))
            SQL);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.water_points (osm_type, osm_id, name, category, tags, geom) VALUES
              ('n', 9003, NULL, 'drinking_water', '{}'::jsonb, ST_SetSRID(ST_MakePoint(6.14, 49.61), 4326))
            SQL);
    }

    #[Test]
    public function resupplyPoiInCorridorSuppressesLunchNudge(): void
    {
        // Corridor A carries a restaurant → the 50 km stage must NOT get a lunch nudge.
        $route = [
            ['lat' => 49.60, 'lon' => 6.13],
            ['lat' => 49.61, 'lon' => 6.14],
            ['lat' => 49.62, 'lon' => 6.15],
        ];
        $stage = $this->stageOnRoute($route);
        $this->runScan($route, $stage);

        self::assertContains(
            'restaurant',
            array_map(static fn (PointOfInterest $poi): string => $poi->category, $stage->pois),
            'The seeded restaurant in the corridor must be detected from the local index',
        );
        self::assertFalse(
            array_any($stage->alerts, static fn (Alert $alert): bool => AlertType::NUDGE === $alert->type),
            'A long stage with a resupply POI in the corridor must not raise the lunch nudge',
        );
    }

    #[Test]
    public function longStageWithoutResupplyPoiEmitsLunchNudge(): void
    {
        // Corridor B carries only a viewpoint (no resupply) → lunch nudge fires.
        $route = [
            ['lat' => 49.99, 'lon' => 6.99],
            ['lat' => 50.00, 'lon' => 7.00],
            ['lat' => 50.01, 'lon' => 7.01],
        ];
        $stage = $this->stageOnRoute($route);
        $this->runScan($route, $stage);

        self::assertContains(
            'viewpoint',
            array_map(static fn (PointOfInterest $poi): string => $poi->category, $stage->pois),
        );
        self::assertTrue(
            array_any($stage->alerts, static fn (Alert $alert): bool => AlertType::NUDGE === $alert->type),
            'A long stage with no resupply POI in the corridor must raise the lunch nudge',
        );
    }

    /**
     * @param list<array{lat: float, lon: float}> $route
     */
    private function stageOnRoute(array $route): Stage
    {
        $geometry = array_map(static fn (array $point): Coordinate => new Coordinate($point['lat'], $point['lon']), $route);

        return new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 50.0, // >= ScanPoisHandler::LUNCH_NUDGE_DISTANCE_KM
            elevation: 300.0,
            startPoint: $geometry[0],
            endPoint: $geometry[\count($geometry) - 1],
            geometry: $geometry,
        );
    }

    /**
     * @param list<array{lat: float, lon: float}> $route
     */
    private function runScan(array $route, Stage $stage): void
    {
        $decimated = array_map(
            static fn (array $point): array => ['lat' => $point['lat'], 'lon' => $point['lon'], 'ele' => 0.0],
            $route,
        );

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(new TripRequest());
        $tripStateManager->method('getDecimatedPoints')->willReturn($decimated);

        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        $haversine = new HaversineDistance();

        $handler = new ScanPoisHandler(
            $computationTracker,
            $publisher,
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $tripStateManager,
            new PoiRepository($this->connection),
            new WaterPointRepository($this->connection),
            new GeometryBasedDistributor($haversine),
            new SupplyTimelineBuilder($haversine),
            $this->createStub(RiderTimeEstimatorInterface::class),
            $translator,
            $this->createStub(MessageBusInterface::class),
        );

        $handler(new ScanPois('trip-1'));
    }
}
