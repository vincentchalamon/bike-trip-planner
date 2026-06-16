<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\RiderTimeEstimatorInterface;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Geo\HaversineDistance;
use App\Geo\NearbyNameDeduplicator;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanPois;
use App\MessageHandler\ScanPoisHandler;
use App\Osm\WaterPointRepositoryInterface;
use App\Poi\PoiSourceInterface;
use App\Poi\PoiSourceRegistry;
use App\Poi\SupplyTimelineBuilder;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ScanPoisHandlerTest extends TestCase
{
    private function createStage(string $tripId, int $dayNumber, float $distance = 80.0): Stage
    {
        return new Stage(
            tripId: $tripId,
            dayNumber: $dayNumber,
            distance: $distance,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
            geometry: [
                new Coordinate(48.0, 2.0),
                new Coordinate(48.1, 2.1),
                new Coordinate(48.2, 2.2),
                new Coordinate(48.3, 2.3),
                new Coordinate(48.4, 2.4),
                new Coordinate(48.5, 2.5),
            ],
        );
    }

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        PoiSourceRegistry $poiSourceRegistry,
        WaterPointRepositoryInterface $waterPointRepository,
        GeometryDistributorInterface $distributor,
        GeoDistanceInterface $haversine,
        RiderTimeEstimatorInterface $riderTimeEstimator,
    ): ScanPoisHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => $id.': '.json_encode($params),
        );

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        return new ScanPoisHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $poiSourceRegistry,
            $waterPointRepository,
            $distributor,
            new SupplyTimelineBuilder($haversine),
            $riderTimeEstimator,
            $translator,
            $this->createStub(MessageBusInterface::class),
        );
    }

    /**
     * @param list<Stage>|null $stages
     */
    private function createTripStateManager(
        ?array $stages,
        string $locale = 'en',
        ?TripRequest $tripRequest = null,
    ): TripRequestRepositoryInterface {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn($locale);
        $tripStateManager->method('getRequest')->willReturn($tripRequest ?? new TripRequest());
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.0, 'lon' => 2.0, 'ele' => 0.0],
            ['lat' => 48.5, 'lon' => 2.5, 'ele' => 0.0],
        ]);

        return $tripStateManager;
    }

    /**
     * Real registry wrapping a single fake source returning $pois. Uses the real
     * deduplicator (transparent here: every fixture has a distinct name). An
     * optional callback captures the corridor route the source receives.
     *
     * @param list<array{name: ?string, category: string, lat: float, lon: float}> $pois
     * @param (\Closure(list<array{lat: float, lon: float}>, int): void)|null       $captureRoute
     */
    private function poiSourceRegistry(array $pois, ?\Closure $captureRoute = null): PoiSourceRegistry
    {
        $source = new class($pois, $captureRoute) implements PoiSourceInterface {
            /**
             * @param list<array{name: ?string, category: string, lat: float, lon: float}>     $pois
             * @param (\Closure(list<array{lat: float, lon: float}>, int): void)|null $captureRoute
             */
            public function __construct(private array $pois, private ?\Closure $captureRoute)
            {
            }

            public function fetchInCorridor(array $route, int $radiusMeters): array
            {
                if (null !== $this->captureRoute) {
                    ($this->captureRoute)($route, $radiusMeters);
                }

                return array_map(static fn (array $p): array => [
                    'name' => $p['name'] ?? $p['category'],
                    'category' => $p['category'],
                    'lat' => $p['lat'],
                    'lon' => $p['lon'],
                    'wikidataId' => null,
                    'source' => 'osm',
                ], $this->pois);
            }
        };

        return new PoiSourceRegistry([$source], new NearbyNameDeduplicator(new HaversineDistance()));
    }

    /**
     * @param list<array{name: ?string, category: string, lat: float, lon: float}> $waterPoints
     */
    private function waterPointRepository(array $waterPoints = []): WaterPointRepositoryInterface
    {
        $repository = $this->createStub(WaterPointRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturn($waterPoints);

        return $repository;
    }

    /**
     * @return array{GeoDistanceInterface&Stub, RiderTimeEstimatorInterface&Stub}
     */
    private function createDefaultStubs(): array
    {
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(10.0);
        $haversine->method('inMeters')->willReturnCallback(
            static fn (float $lat1, float $lon1, float $lat2, float $lon2): float => ($lat1 === $lat2 && $lon1 === $lon2) ? 0.0 : 10000.0,
        );

        $riderTimeEstimator = $this->createStub(RiderTimeEstimatorInterface::class);

        return [$haversine, $riderTimeEstimator];
    }

    #[Test]
    public function allResupplyPoisClosedAtEstimatedTimeEmitsWarning(): void
    {
        $stage = $this->createStage('trip-1', 1, 80.0);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $poiRepository = $this->poiSourceRegistry([
            ['name' => 'Le Bistrot', 'category' => 'restaurant', 'lat' => 48.2, 'lon' => 2.2],
            ['name' => 'Chez Paul', 'category' => 'restaurant', 'lat' => 48.3, 'lon' => 2.3],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls(
            [0 => [
                ['name' => 'Le Bistrot', 'category' => 'restaurant', 'lat' => 48.2, 'lon' => 2.2],
                ['name' => 'Chez Paul', 'category' => 'restaurant', 'lat' => 48.3, 'lon' => 2.3],
            ]],
            [],
        );

        [$haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        // 16:00 → both restaurants closed (restaurants: 12-14, 19-22)
        $riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(16.0);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $poiRepository, $this->waterPointRepository(), $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        $poisScannedEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::POIS_SCANNED === $e['type']);
        self::assertCount(1, $poisScannedEvents);
        $data = array_first($poisScannedEvents)['payload'];
        $alerts = $data['alerts'] ?? [];
        self::assertTrue(
            array_any($alerts, static fn (array $a): bool => 'warning' === $a['type']),
            'Expected at least one warning alert for resupply timing',
        );
    }

    #[Test]
    public function atLeastOneOpenResupplyPoiEmitsNoTimingWarning(): void
    {
        $stage = $this->createStage('trip-1', 1, 80.0);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $poiRepository = $this->poiSourceRegistry([
            ['name' => 'Le Bistrot', 'category' => 'restaurant', 'lat' => 48.2, 'lon' => 2.2],
            ['name' => 'Carrefour', 'category' => 'supermarket', 'lat' => 48.3, 'lon' => 2.3],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls(
            [0 => [
                ['name' => 'Le Bistrot', 'category' => 'restaurant', 'lat' => 48.2, 'lon' => 2.2],
                ['name' => 'Carrefour', 'category' => 'supermarket', 'lat' => 48.3, 'lon' => 2.3],
            ]],
            [],
        );

        [$haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        // 15:00 → restaurant closed, supermarket open (9-20)
        $riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(15.0);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $poiRepository, $this->waterPointRepository(), $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        $poisScannedEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::POIS_SCANNED === $e['type']);
        self::assertCount(1, $poisScannedEvents);
        $data = array_first($poisScannedEvents)['payload'];
        $alerts = $data['alerts'] ?? [];
        self::assertFalse(
            array_any($alerts, static fn (array $a): bool => 'warning' === $a['type']),
            'Expected no timing warning since supermarket is open at 15:00',
        );
    }

    #[Test]
    public function noResupplyPoisEmitsNoTimingWarning(): void
    {
        $stage = $this->createStage('trip-1', 1, 80.0);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $poiRepository = $this->poiSourceRegistry([
            ['name' => 'Belvedere', 'category' => 'viewpoint', 'lat' => 48.2, 'lon' => 2.2],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls(
            [0 => [
                ['name' => 'Belvedere', 'category' => 'viewpoint', 'lat' => 48.2, 'lon' => 2.2],
            ]],
            [],
        );

        [$haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $poiRepository, $this->waterPointRepository(), $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        $poisScannedEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::POIS_SCANNED === $e['type']);
        self::assertCount(1, $poisScannedEvents);
        $data = array_first($poisScannedEvents)['payload'];
        $alerts = $data['alerts'] ?? [];
        self::assertFalse(
            array_any($alerts, static fn (array $a): bool => 'warning' === $a['type']),
            'Expected no timing warning when there are no resupply POIs',
        );
    }

    #[Test]
    public function noStagesReturnsEarly(): void
    {
        $tripStateManager = $this->createTripStateManager(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        [$haversine, $riderTimeEstimator] = $this->createDefaultStubs();
        $distributor = $this->createStub(GeometryDistributorInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $this->poiSourceRegistry([]), $this->waterPointRepository(), $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));
    }

    #[Test]
    public function lunchNudgeEmittedForLongStageWithoutResupplyPois(): void
    {
        // Stage >= 40km with no resupply POIs in the local index → lunch nudge
        $stage = $this->createStage('trip-1', 1, 50.0);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        [$haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $this->poiSourceRegistry([]), $this->waterPointRepository(), $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        $poisScannedEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::POIS_SCANNED === $e['type']);
        self::assertCount(1, $poisScannedEvents);
        $data = array_first($poisScannedEvents)['payload'];
        $alerts = $data['alerts'] ?? [];
        self::assertCount(1, $alerts);
        self::assertSame('nudge', $alerts[0]['type']);
    }

    #[Test]
    public function resolveScheduleMapsBakeryCorrectly(): void
    {
        $stage = $this->createStage('trip-1', 1, 80.0);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $poiRepository = $this->poiSourceRegistry([
            ['name' => 'Boulangerie', 'category' => 'bakery', 'lat' => 48.2, 'lon' => 2.2],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls(
            [0 => [
                ['name' => 'Boulangerie', 'category' => 'bakery', 'lat' => 48.2, 'lon' => 2.2],
            ]],
            [],
        );

        [$haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        // 10:00 → bakery open (7-13 slot)
        $riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(10.0);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $poiRepository, $this->waterPointRepository(), $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        $poisScannedEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::POIS_SCANNED === $e['type']);
        self::assertCount(1, $poisScannedEvents);
        $data = array_first($poisScannedEvents)['payload'];
        $alerts = $data['alerts'] ?? [];
        self::assertFalse(
            array_any($alerts, static fn (array $a): bool => 'warning' === $a['type']),
            'Expected no timing warning since bakery is open at 10:00',
        );
    }

    #[Test]
    public function poisWithin500mAreClusteredIntoSingleMarker(): void
    {
        $stage = $this->createStage('trip-1', 1, 80.0);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $poiRepository = $this->poiSourceRegistry([
            ['name' => 'Bistrot A', 'category' => 'restaurant', 'lat' => 48.2, 'lon' => 2.2],
            ['name' => 'Bistrot B', 'category' => 'restaurant', 'lat' => 48.2, 'lon' => 2.2001],
            ['name' => 'Remote Bistrot', 'category' => 'restaurant', 'lat' => 48.5, 'lon' => 2.5],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls(
            [0 => [
                ['name' => 'Bistrot A', 'category' => 'restaurant', 'lat' => 48.2, 'lon' => 2.2],
                ['name' => 'Bistrot B', 'category' => 'restaurant', 'lat' => 48.2, 'lon' => 2.2001],
                ['name' => 'Remote Bistrot', 'category' => 'restaurant', 'lat' => 48.5, 'lon' => 2.5],
            ]],
            [],
        );

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(10.0);
        $haversine->method('inMeters')->willReturnCallback(
            static function (float $lat1, float $lon1, float $lat2, float $lon2): float {
                if ($lat1 === $lat2 && abs($lon1 - $lon2) < 0.001) {
                    return 10.0; // within 500m
                }

                return 40000.0; // far apart
            },
        );

        $riderTimeEstimator = $this->createStub(RiderTimeEstimatorInterface::class);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $poiRepository, $this->waterPointRepository(), $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        $timelineEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::SUPPLY_TIMELINE === $e['type']);
        self::assertCount(1, $timelineEvents);

        $markers = array_first($timelineEvents)['payload']['markers'];
        self::assertCount(2, $markers, 'Expected 2 markers: one cluster for close POIs, one for the remote POI');
        self::assertSame('food', $markers[0]['type']);
        self::assertCount(2, $markers[0]['food'], 'Expected 2 food items in the clustered marker');
        self::assertSame('food', $markers[1]['type']);
        self::assertCount(1, $markers[1]['food'], 'Expected 1 food item in the remote marker');
    }

    #[Test]
    public function poiRepositoryQueriedWithDecimatedRouteCorridor(): void
    {
        $stage1 = $this->createStage('trip-1', 1, 50.0);
        $stage2 = $this->createStage('trip-1', 2, 50.0);
        $tripStateManager = $this->createTripStateManager([$stage1, $stage2]);

        // The corridor read uses the decimated points as a {lat, lon} route.
        $capturedRoute = null;
        $registry = $this->poiSourceRegistry([], static function (array $route) use (&$capturedRoute): void {
            $capturedRoute = $route;
        });

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        [$haversine, $riderTimeEstimator] = $this->createDefaultStubs();
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $this->waterPointRepository(), $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        self::assertSame([
            ['lat' => 48.0, 'lon' => 2.0],
            ['lat' => 48.5, 'lon' => 2.5],
        ], $capturedRoute);
    }

    #[Test]
    public function fallsBackToStageGeometryWhenDecimatedPointsUnavailable(): void
    {
        $stage = $this->createStage('trip-1', 1, 80.0);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn(new TripRequest());
        $tripStateManager->method('getDecimatedPoints')->willReturn(null);

        // No decimated points → corridor falls back to the 6-point stage geometry.
        $capturedRoute = null;
        $registry = $this->poiSourceRegistry([], static function (array $route) use (&$capturedRoute): void {
            $capturedRoute = $route;
        });

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        [$haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $this->waterPointRepository(), $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        self::assertIsArray($capturedRoute);
        self::assertCount(6, $capturedRoute);
        self::assertSame(['lat' => 48.0, 'lon' => 2.0], $capturedRoute[0]);

        $poisScannedEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::POIS_SCANNED === $e['type']);
        self::assertCount(1, $poisScannedEvents);
    }

    #[Test]
    public function chainedPoisBeyondAnchorRadiusAreNotMerged(): void
    {
        // A (anchor) → B (490m, within cluster) → C (980m from A, beyond anchor radius).
        $stage = $this->createStage('trip-1', 1, 80.0);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $poiRepository = $this->poiSourceRegistry([
            ['name' => 'POI A', 'category' => 'restaurant', 'lat' => 48.0, 'lon' => 2.0],
            ['name' => 'POI B', 'category' => 'restaurant', 'lat' => 48.0, 'lon' => 2.005],
            ['name' => 'POI C', 'category' => 'restaurant', 'lat' => 48.0, 'lon' => 2.010],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls(
            [0 => [
                ['name' => 'POI A', 'category' => 'restaurant', 'lat' => 48.0, 'lon' => 2.0],
                ['name' => 'POI B', 'category' => 'restaurant', 'lat' => 48.0, 'lon' => 2.005],
                ['name' => 'POI C', 'category' => 'restaurant', 'lat' => 48.0, 'lon' => 2.010],
            ]],
            [],
        );

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(10.0);
        $haversine->method('inMeters')->willReturnCallback(
            static function (float $lat1, float $lon1, float $lat2, float $lon2): float {
                $lonDiff = abs($lon1 - $lon2);
                if ($lonDiff < 0.006) {
                    return 490.0; // A→B within radius
                }

                return 980.0; // A→C beyond anchor radius
            },
        );

        $riderTimeEstimator = $this->createStub(RiderTimeEstimatorInterface::class);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $poiRepository, $this->waterPointRepository(), $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        $timelineEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::SUPPLY_TIMELINE === $e['type']);
        self::assertCount(1, $timelineEvents);

        $markers = array_first($timelineEvents)['payload']['markers'];
        self::assertCount(2, $markers, "C must not chain into A's cluster: anchor-based check only");
        self::assertCount(2, $markers[0]['food'], 'A and B should be in the same cluster');
        self::assertCount(1, $markers[1]['food'], 'C must be isolated in its own cluster');
    }
}
