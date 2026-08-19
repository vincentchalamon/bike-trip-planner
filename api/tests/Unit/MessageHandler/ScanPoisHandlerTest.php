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
use App\Poi\PoiLabelResolver;
use App\Poi\PoiSourceInterface;
use App\Poi\PoiSourceRegistry;
use App\Poi\ResupplyBuilder;
use App\Poi\SupplyTimelineBuilder;
use App\Repository\TripRequestRepositoryInterface;
use App\Tests\Unit\AlertMessageTestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ScanPoisHandlerTest extends TestCase
{
    use AlertMessageTestTrait;

    #[Test]
    public function anonymousResupplyPoisAreAllKeptWithALocalisedLabel(): void
    {
        // Two nameless bakeries 33 m apart in the same village centre: both are
        // published, each labelled by its category in the trip locale rather than
        // by the raw OSM slug (issue #874).
        $stage = $this->createStage('trip-1', 1, 80.0);
        $tripStateManager = $this->createTripStateManager([$stage], 'fr');

        $registry = $this->poiSourceRegistry([
            ['name' => null, 'category' => 'bakery', 'lat' => 48.1000, 'lon' => 2.1, 'openingHours' => null, 'website' => null],
            ['name' => null, 'category' => 'bakery', 'lat' => 48.1003, 'lon' => 2.1, 'openingHours' => null, 'website' => null],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnCallback(
            static fn (array $items): array => [0 => $items],
        );

        [$haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $this->waterPointRepository(), $distributor, $haversine, $riderTimeEstimator, $this->createAlertTranslator());
        $handler(new ScanPois('trip-1'));

        $poisScannedEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::POIS_SCANNED === $e['type']);
        self::assertCount(1, $poisScannedEvents);
        $data = array_first($poisScannedEvents)['payload'];

        // Both bakeries are food; with the default (0.0) passage-time stub lunch
        // lands at the arrival, so both are kept as the lunch food picks.
        self::assertSame(['Boulangerie', 'Boulangerie'], array_column($data['resupply']['foodAtLunch'], 'name'));
    }

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
        ?TranslatorInterface $translator = null,
    ): ScanPoisHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $stubTranslator = $this->createStub(TranslatorInterface::class);
        $stubTranslator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => $id.': '.json_encode($params),
        );
        $translator ??= $stubTranslator;

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
            new ResupplyBuilder(),
            new PoiLabelResolver($translator),
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
     * A raw corridor POI as the sources and the distributor hand it over.
     *
     * @return array{name: string, category: string, lat: float, lon: float, openingHours: string|null, website: string|null}
     */
    private function poi(string $name, string $category, float $lat, float $lon, ?string $openingHours = null): array
    {
        return [
            'name' => $name,
            'category' => $category,
            'lat' => $lat,
            'lon' => $lon,
            'openingHours' => $openingHours,
            'website' => null,
        ];
    }

    /**
     * Real registry wrapping a single fake source returning $pois. Uses the real
     * deduplicator (transparent here: every fixture has a distinct name). An
     * optional callback captures the corridor route the source receives.
     *
     * @param list<array{name: string|null, category: string, lat: float, lon: float, osmType?: ?string, osmId?: ?int, openingHours: string|null, website: string|null}> $pois
     * @param (\Closure(list<array{lat: float, lon: float}>, int): void)|null                                                                                            $captureRoute
     */
    private function poiSourceRegistry(array $pois, ?\Closure $captureRoute = null): PoiSourceRegistry
    {
        $source = new readonly class ($pois, $captureRoute) implements PoiSourceInterface {
            /**
             * @param list<array{name: string|null, category: string, lat: float, lon: float, osmType?: ?string, osmId?: ?int, openingHours: string|null, website: string|null}> $pois
             * @param (\Closure(list<array{lat: float, lon: float}>, int): void)|null                                                                                            $captureRoute
             */
            public function __construct(private array $pois, private ?\Closure $captureRoute)
            {
            }

            public function fetchInCorridor(array $route, int $radiusMeters): array
            {
                if ($this->captureRoute instanceof \Closure) {
                    ($this->captureRoute)($route, $radiusMeters);
                }

                return array_map(static fn (array $p): array => [
                    'name' => $p['name'],
                    'category' => $p['category'],
                    'lat' => $p['lat'],
                    'lon' => $p['lon'],
                    'osmType' => $p['osmType'] ?? null,
                    'osmId' => $p['osmId'] ?? null,
                    'openingHours' => $p['openingHours'],
                    'website' => $p['website'],
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

        // Both carry real OSM hours, so the passage time can actually be judged.
        $pois = [
            $this->poi('Le Bistrot', 'restaurant', 48.2, 2.2, '12:00-14:00,19:00-22:00'),
            $this->poi('Chez Paul', 'restaurant', 48.3, 2.3, '12:00-14:30'),
        ];
        $poiRepository = $this->poiSourceRegistry($pois);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls([0 => $pois], []);

        [$haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        // 16:00 → both restaurants closed according to their own opening_hours
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

        $pois = [
            $this->poi('Le Bistrot', 'restaurant', 48.2, 2.2, '12:00-14:00,19:00-22:00'),
            $this->poi('Carrefour', 'supermarket', 48.3, 2.3, '09:00-20:00'),
        ];
        $poiRepository = $this->poiSourceRegistry($pois);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls([0 => $pois], []);

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

        $pois = [$this->poi('Belvedere', 'viewpoint', 48.2, 2.2)];
        $poiRepository = $this->poiSourceRegistry($pois);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls([0 => $pois], []);

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
    public function lunchNudgeSkippedOnRestDay(): void
    {
        // Same setup as lunchNudgeEmittedForLongStageWithoutResupplyPois, flagged as a
        // rest day: no mid-ride lunch nudge, but the POI scan still publishes.
        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 50.0,
            elevation: 0.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.0, 2.0),
            geometry: [new Coordinate(48.0, 2.0)],
            isRestDay: true,
        );
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
        self::assertArrayNotHasKey('alerts', $data);
    }

    #[Test]
    public function resupplyTimingWarningSkippedOnRestDay(): void
    {
        // Same setup as allResupplyPoisClosedAtEstimatedTimeEmitsWarning, flagged as a
        // rest day: no timing warning, but the POIs are still scanned and published,
        // because knowing what is around is useful on the spot.
        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
            geometry: [
                new Coordinate(48.0, 2.0),
                new Coordinate(48.2, 2.2),
                new Coordinate(48.5, 2.5),
            ],
            isRestDay: true,
        );
        $tripStateManager = $this->createTripStateManager([$stage]);

        $pois = [
            $this->poi('Le Bistrot', 'restaurant', 48.2, 2.2, '12:00-14:00,19:00-22:00'),
            $this->poi('Chez Paul', 'restaurant', 48.3, 2.3, '12:00-14:30'),
        ];
        $poiRepository = $this->poiSourceRegistry($pois);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls([0 => $pois], []);

        [$haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        // 16:00 → both restaurants closed per their own opening_hours, so without the
        // rest-day guard this stage would emit the timing warning.
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
        self::assertFalse(
            array_any($data['alerts'] ?? [], static fn (array $a): bool => 'warning' === $a['type']),
            'Expected no resupply timing warning on a rest day',
        );
        self::assertNotEmpty($data['resupply']['foodAtLunch'] ?? [], 'Resupply must still be published on a rest day');
    }

    #[Test]
    public function resolveScheduleMapsBakeryCorrectly(): void
    {
        $stage = $this->createStage('trip-1', 1, 80.0);
        $tripStateManager = $this->createTripStateManager([$stage]);

        // No OSM hours: the category fallback is still allowed to conclude "open".
        $pois = [$this->poi('Boulangerie', 'bakery', 48.2, 2.2)];
        $poiRepository = $this->poiSourceRegistry($pois);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls([0 => $pois], []);

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

    /**
     * Runs the handler on an 80 km stage carrying $pois, the rider passing each of
     * them at $passageTime, and returns the alerts published for that stage.
     *
     * @param list<array{name: string|null, category: string, lat: float, lon: float, openingHours: string|null, website: string|null}> $pois
     *
     * @return list<array<string, mixed>>
     */
    private function alertsForStage(array $pois, float $passageTime, ?TripRequest $tripRequest = null): array
    {
        $stage = $this->createStage('trip-1', 1, 80.0);
        $tripStateManager = $this->createTripStateManager([$stage], 'en', $tripRequest);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls([0 => $pois], []);

        [$haversine, $riderTimeEstimator] = $this->createDefaultStubs();
        $riderTimeEstimator->method('estimateTimeAtDistance')->willReturn($passageTime);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $this->poiSourceRegistry($pois), $this->waterPointRepository(), $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        $poisScannedEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::POIS_SCANNED === $e['type']);
        self::assertCount(1, $poisScannedEvents);

        /** @var list<array<string, mixed>> $alerts */
        $alerts = array_first($poisScannedEvents)['payload']['alerts'] ?? [];

        return $alerts;
    }

    /**
     * @param list<array<string, mixed>> $alerts
     */
    private function hasTimingWarning(array $alerts): bool
    {
        return array_any($alerts, static fn (array $alert): bool => 'warning' === $alert['type']);
    }

    #[Test]
    public function poiWithoutOpeningHoursDoesNotTriggerTimingWarning(): void
    {
        // 16:00 is outside the generic restaurant slots (12-14, 19-22), which is
        // exactly what used to raise the warning. OSM knows nothing about this
        // restaurant's hours, so there is nothing to warn about.
        $alerts = $this->alertsForStage([$this->poi('Le Bistrot', 'restaurant', 48.2, 2.2)], 16.0);

        self::assertFalse(
            $this->hasTimingWarning($alerts),
            'A POI with unknown opening hours must never raise alert.resupply.timing_warning',
        );
    }

    #[Test]
    public function poiWithUnparsableOpeningHoursDoesNotTriggerTimingWarning(): void
    {
        // A shape the parser does not model is unknown, not closed.
        $alerts = $this->alertsForStage([$this->poi('Le Bistrot', 'restaurant', 48.2, 2.2, 'sunrise-sunset; by appointment')], 16.0);

        self::assertFalse(
            $this->hasTimingWarning($alerts),
            'An opening_hours value that could not be read must be treated as unknown',
        );
    }

    #[Test]
    public function unknownHoursOnOnePoiSuppressTheWarningForTheWholeStage(): void
    {
        $alerts = $this->alertsForStage([
            $this->poi('Le Bistrot', 'restaurant', 48.2, 2.2, '12:00-14:00'),
            $this->poi('Chez Paul', 'restaurant', 48.3, 2.3),
        ], 16.0);

        self::assertFalse(
            $this->hasTimingWarning($alerts),
            'One POI whose hours are unknown makes the stage inconclusive',
        );
    }

    #[Test]
    public function realOpeningHoursTakePrecedenceOverTheCategoryFallback(): void
    {
        // The generic restaurant slots would call 16:00 closed; OSM says otherwise.
        $alerts = $this->alertsForStage([$this->poi('Le Bistrot', 'restaurant', 48.2, 2.2, '15:00-18:00')], 16.0);

        self::assertFalse(
            $this->hasTimingWarning($alerts),
            'The real opening_hours must win over the category-typical slots',
        );
    }

    #[Test]
    public function realOpeningHoursOutsideThePassageTimeEmitTheWarning(): void
    {
        // Conversely, a POI the generic slots would call open (a supermarket at
        // 10:00) is warned about when its own hours say it is closed.
        $alerts = $this->alertsForStage([$this->poi('Carrefour', 'supermarket', 48.2, 2.2, '14:00-19:00')], 10.0);

        self::assertTrue(
            $this->hasTimingWarning($alerts),
            'A POI known to be closed at the passage time must raise the warning',
        );
    }

    #[Test]
    public function weekdayDependentHoursAreEvaluatedOnTheStageDate(): void
    {
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-08-02'); // a Sunday

        $pois = [$this->poi('Carrefour', 'supermarket', 48.2, 2.2, 'Mo-Sa 09:00-19:00')];

        self::assertTrue(
            $this->hasTimingWarning($this->alertsForStage($pois, 10.0, $request)),
            'The shop is closed on Sundays, and day 1 of this trip is a Sunday',
        );

        $request->startDate = new \DateTimeImmutable('2026-08-03'); // a Monday

        self::assertFalse(
            $this->hasTimingWarning($this->alertsForStage($pois, 10.0, $request)),
            'The same shop is open on Mondays',
        );
    }

    #[Test]
    public function weekdayDependentHoursAreInconclusiveWithoutAStartDate(): void
    {
        // No start date → the weekday is unknown, and "Mo-Sa" cannot be resolved.
        $alerts = $this->alertsForStage([$this->poi('Carrefour', 'supermarket', 48.2, 2.2, 'Mo-Sa 09:00-19:00')], 21.0);

        self::assertTrue(
            $this->hasTimingWarning($alerts),
            '21:00 is outside the slot on every weekday, so the answer holds without a date',
        );

        $alerts = $this->alertsForStage([$this->poi('Carrefour', 'supermarket', 48.2, 2.2, 'Mo-Sa 09:00-19:00')], 10.0);

        self::assertFalse(
            $this->hasTimingWarning($alerts),
            '10:00 depends on the weekday, which is unknown here: nothing can be concluded',
        );
    }

    #[Test]
    public function poisWithin500mAreClusteredIntoSingleMarker(): void
    {
        $stage = $this->createStage('trip-1', 1, 80.0);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $pois = [
            $this->poi('Bistrot A', 'restaurant', 48.2, 2.2),
            $this->poi('Bistrot B', 'restaurant', 48.2, 2.2001),
            $this->poi('Remote Bistrot', 'restaurant', 48.5, 2.5),
        ];
        $poiRepository = $this->poiSourceRegistry($pois);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls([0 => $pois], []);

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

        $pois = [
            $this->poi('POI A', 'restaurant', 48.0, 2.0),
            $this->poi('POI B', 'restaurant', 48.0, 2.005),
            $this->poi('POI C', 'restaurant', 48.0, 2.010),
        ];
        $poiRepository = $this->poiSourceRegistry($pois);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls([0 => $pois], []);

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
