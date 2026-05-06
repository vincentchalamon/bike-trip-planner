<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use PHPUnit\Framework\MockObject\Stub;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\RiderTimeEstimatorInterface;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanPois;
use App\MessageHandler\ScanPoisHandler;
use App\Poi\SupplyTimelineBuilder;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use PHPUnit\Framework\Attributes\Test;
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
        ScannerInterface $scanner,
        QueryBuilderInterface $queryBuilder,
        GeometryDistributorInterface $distributor,
        GeoDistanceInterface $haversine,
        RiderTimeEstimatorInterface $riderTimeEstimator,
    ): ScanPoisHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);
        $computationTracker->method('areAllEnrichmentsCompleted')->willReturn(false);
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
            $scanner,
            $queryBuilder,
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
     * @return array{QueryBuilderInterface&Stub, GeoDistanceInterface&Stub, RiderTimeEstimatorInterface&Stub}
     */
    private function createDefaultStubs(): array
    {
        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildPoiQuery')->willReturn('query');
        $queryBuilder->method('buildCemeteryQuery')->willReturn('cemetery_query');

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(10.0);
        $haversine->method('inMeters')->willReturnCallback(
            static fn (float $lat1, float $lon1, float $lat2, float $lon2): float => ($lat1 === $lat2 && $lon1 === $lon2) ? 0.0 : 10000.0,
        );

        $riderTimeEstimator = $this->createStub(RiderTimeEstimatorInterface::class);

        return [$queryBuilder, $haversine, $riderTimeEstimator];
    }

    #[Test]
    public function allResupplyPoisClosedAtEstimatedTimeEmitsWarning(): void
    {
        $stage = $this->createStage('trip-1', 1, 80.0);

        // Pre-populate with two resupply POIs (restaurant category)
        $tripStateManager = $this->createTripStateManager([$stage]);

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('queryBatch')->willReturn([
            'poi' => [
                'elements' => [
                    ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['amenity' => 'restaurant', 'name' => 'Le Bistrot']],
                    ['lat' => 48.3, 'lon' => 2.3, 'tags' => ['amenity' => 'restaurant', 'name' => 'Chez Paul']],
                ],
            ],
            'cemetery' => ['elements' => []],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls(
            [0 => [
                ['name' => 'Le Bistrot', 'category' => 'restaurant', 'lat' => 48.2, 'lon' => 2.2],
                ['name' => 'Chez Paul', 'category' => 'restaurant', 'lat' => 48.3, 'lon' => 2.3],
            ]],
            [],
        );

        [$queryBuilder, $haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        // Return a time outside restaurant hours (restaurants: 12-14, 19-22)
        // Return 16.0 (4 PM) → closed for both restaurants
        $riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(16.0);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine, $riderTimeEstimator);
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

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('queryBatch')->willReturn([
            'poi' => [
                'elements' => [
                    ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['amenity' => 'restaurant', 'name' => 'Le Bistrot']],
                    ['lat' => 48.3, 'lon' => 2.3, 'tags' => ['shop' => 'supermarket', 'name' => 'Carrefour']],
                ],
            ],
            'cemetery' => ['elements' => []],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls(
            [0 => [
                ['name' => 'Le Bistrot', 'category' => 'restaurant', 'lat' => 48.2, 'lon' => 2.2],
                ['name' => 'Carrefour', 'category' => 'supermarket', 'lat' => 48.3, 'lon' => 2.3],
            ]],
            [],
        );

        [$queryBuilder, $haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        // Return 15.0 (3 PM) → restaurant closed, but supermarket open (9-20)
        $riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(15.0);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine, $riderTimeEstimator);
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
        // Stage with only non-resupply POIs (tourism category)
        $stage = $this->createStage('trip-1', 1, 80.0);

        $tripStateManager = $this->createTripStateManager([$stage]);

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('queryBatch')->willReturn([
            'poi' => [
                'elements' => [
                    ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['tourism' => 'viewpoint', 'name' => 'Belvedere']],
                ],
            ],
            'cemetery' => ['elements' => []],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls(
            [0 => [
                ['name' => 'Belvedere', 'category' => 'viewpoint', 'lat' => 48.2, 'lon' => 2.2],
            ]],
            [],
        );

        [$queryBuilder, $haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine, $riderTimeEstimator);
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

        $scanner = $this->createStub(ScannerInterface::class);
        [$queryBuilder, $haversine, $riderTimeEstimator] = $this->createDefaultStubs();
        $distributor = $this->createStub(GeometryDistributorInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));
    }

    #[Test]
    public function lunchNudgeEmittedForLongStageWithoutResupplyPois(): void
    {
        // Stage >= 40km with no resupply POIs → lunch nudge
        $stage = $this->createStage('trip-1', 1, 50.0);

        $tripStateManager = $this->createTripStateManager([$stage]);

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('queryBatch')->willReturn([
            'poi' => ['elements' => []],
            'cemetery' => ['elements' => []],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        [$queryBuilder, $haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine, $riderTimeEstimator);
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
        // Bakery open 7-13 and 15-19; test at 10:00 → open, no warning
        $stage = $this->createStage('trip-1', 1, 80.0);

        $tripStateManager = $this->createTripStateManager([$stage]);

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('queryBatch')->willReturn([
            'poi' => [
                'elements' => [
                    ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['shop' => 'bakery', 'name' => 'Boulangerie']],
                ],
            ],
            'cemetery' => ['elements' => []],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls(
            [0 => [
                ['name' => 'Boulangerie', 'category' => 'bakery', 'lat' => 48.2, 'lon' => 2.2],
            ]],
            [],
        );

        [$queryBuilder, $haversine, $riderTimeEstimator] = $this->createDefaultStubs();

        // 10:00 AM → bakery open (7-13 slot)
        $riderTimeEstimator->method('estimateTimeAtDistance')->willReturn(10.0);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine, $riderTimeEstimator);
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
        // Two restaurants very close (< 500m apart) → grouped into one marker
        // One restaurant far away (> 500m from the others) → distinct marker
        $stage = $this->createStage('trip-1', 1, 80.0);

        $tripStateManager = $this->createTripStateManager([$stage]);

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('queryBatch')->willReturn([
            'poi' => [
                'elements' => [
                    ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['amenity' => 'restaurant', 'name' => 'Bistrot A']],
                    ['lat' => 48.2, 'lon' => 2.2001, 'tags' => ['amenity' => 'restaurant', 'name' => 'Bistrot B']],
                    ['lat' => 48.5, 'lon' => 2.5, 'tags' => ['amenity' => 'restaurant', 'name' => 'Remote Bistrot']],
                ],
            ],
            'cemetery' => ['elements' => []],
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

        [$queryBuilder, $riderTimeEstimator] = [$this->createStub(QueryBuilderInterface::class), $this->createStub(RiderTimeEstimatorInterface::class)];
        $queryBuilder->method('buildPoiQuery')->willReturn('query');
        $queryBuilder->method('buildCemeteryQuery')->willReturn('cemetery_query');

        $haversine = $this->createStub(GeoDistanceInterface::class);
        // inKilometers used for cumulative distances along geometry
        $haversine->method('inKilometers')->willReturn(10.0);
        // inMeters: return < 500 for nearby POIs, > 500 for distant ones
        $haversine->method('inMeters')->willReturnCallback(
            static function (float $lat1, float $lon1, float $lat2, float $lon2): float {
                // Same coordinates or very close (Bistrot A & B)
                if ($lat1 === $lat2 && abs($lon1 - $lon2) < 0.001) {
                    return 10.0; // within 500m
                }

                // Distance between close cluster and remote POI
                return 40000.0; // far apart (> 500m)
            },
        );

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        $timelineEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::SUPPLY_TIMELINE === $e['type']);
        self::assertCount(1, $timelineEvents);

        $markers = array_first($timelineEvents)['payload']['markers'];

        // Bistrot A and B are within 500m → one marker; Remote Bistrot is far → another marker
        self::assertCount(2, $markers, 'Expected 2 markers: one cluster for close POIs, one for the remote POI');
        self::assertSame('food', $markers[0]['type']);
        self::assertCount(2, $markers[0]['food'], 'Expected 2 food items in the clustered marker');
        self::assertSame('food', $markers[1]['type']);
        self::assertCount(1, $markers[1]['food'], 'Expected 1 food item in the remote marker');
    }

    #[Test]
    public function poiQueryUsesDecimatedPointsForCacheAlignment(): void
    {
        $stage1 = $this->createStage('trip-1', 1, 50.0);
        $stage2 = $this->createStage('trip-1', 2, 50.0);

        $tripStateManager = $this->createTripStateManager([$stage1, $stage2]);

        $queryBuilder = $this->createMock(QueryBuilderInterface::class);
        // buildPoiQuery should be called once with all decimated points (cache-aligned with ScanAllOsmData)
        $queryBuilder->expects($this->once())
            ->method('buildPoiQuery')
            ->with($this->callback(static fn (array $points): bool => 2 === \count($points)
                && $points[0] instanceof Coordinate
                && 48.0 === $points[0]->lat
                && 2.0 === $points[0]->lon
                && $points[1] instanceof Coordinate
                && 48.5 === $points[1]->lat
                && 2.5 === $points[1]->lon))
            ->willReturn('global_poi_query');
        $queryBuilder->method('buildCemeteryQuery')->willReturn('cemetery_query');

        $scanner = $this->createMock(ScannerInterface::class);
        $scanner->expects($this->once())
            ->method('queryBatch')
            ->with($this->callback(
                // Must have a single 'poi' key and 'cemetery' key (not per-stage keys)
                static fn (array $queries): bool => isset($queries['poi'], $queries['cemetery'])
                && 2 === \count($queries)
            ))
            ->willReturn([
                'poi' => ['elements' => []],
                'cemetery' => ['elements' => []],
            ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(10.0);
        $haversine->method('inMeters')->willReturn(10000.0);

        $riderTimeEstimator = $this->createStub(RiderTimeEstimatorInterface::class);

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));
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

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('queryBatch')->willReturn([
            'poi' => ['elements' => []],
            'cemetery' => ['elements' => []],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilderInterface::class);
        $queryBuilder->expects($this->once())
            ->method('buildPoiQuery')
            ->with($this->callback(static fn (array $points): bool => 6 === \count($points)
                && $points[0] instanceof Coordinate
                && 48.0 === $points[0]->lat
                && 2.0 === $points[0]->lon))
            ->willReturn('fallback_query');
        $queryBuilder->method('buildCemeteryQuery')->willReturn('cemetery_query');

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(10.0);
        $haversine->method('inMeters')->willReturnCallback(
            static fn (float $lat1, float $lon1, float $lat2, float $lon2): float => ($lat1 === $lat2 && $lon1 === $lon2) ? 0.0 : 10000.0,
        );

        $riderTimeEstimator = $this->createStub(RiderTimeEstimatorInterface::class);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        // Handler should complete normally using stage geometry as fallback
        $poisScannedEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::POIS_SCANNED === $e['type']);
        self::assertCount(1, $poisScannedEvents);
    }

    #[Test]
    public function chainedPoisBeyondAnchorRadiusAreNotMerged(): void
    {
        // Scenario: A (anchor, 0 km) → B (490m from A, within cluster) → C (490m from B but 980m from A)
        // Anchor-based: C must NOT join A's cluster (980m > 500m from anchor).
        // A pairwise/chain algorithm would incorrectly merge C because it is within 500m of B.
        $stage = $this->createStage('trip-1', 1, 80.0);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('queryBatch')->willReturn([
            'poi' => [
                'elements' => [
                    ['lat' => 48.0, 'lon' => 2.0, 'tags' => ['amenity' => 'restaurant', 'name' => 'POI A']],
                    ['lat' => 48.0, 'lon' => 2.005, 'tags' => ['amenity' => 'restaurant', 'name' => 'POI B']],
                    ['lat' => 48.0, 'lon' => 2.010, 'tags' => ['amenity' => 'restaurant', 'name' => 'POI C']],
                ],
            ],
            'cemetery' => ['elements' => []],
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

        [$queryBuilder, $riderTimeEstimator] = [
            $this->createStub(QueryBuilderInterface::class),
            $this->createStub(RiderTimeEstimatorInterface::class),
        ];
        $queryBuilder->method('buildPoiQuery')->willReturn('query');
        $queryBuilder->method('buildCemeteryQuery')->willReturn('cemetery_query');

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(10.0);
        $haversine->method('inMeters')->willReturnCallback(
            static function (float $lat1, float $lon1, float $lat2, float $lon2): float {
                $lonDiff = abs($lon1 - $lon2);
                // A→B ≈ 490m (within 500m radius), A→C ≈ 980m (exceeds anchor radius)
                if ($lonDiff < 0.006) {
                    return 490.0;
                }

                return 980.0;
            },
        );

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['tripId' => $tripId, 'type' => $type, 'payload' => $payload];
            });

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine, $riderTimeEstimator);
        $handler(new ScanPois('trip-1'));

        $timelineEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::SUPPLY_TIMELINE === $e['type']);
        self::assertCount(1, $timelineEvents);

        $markers = array_first($timelineEvents)['payload']['markers'];

        // Anchor-based: A+B cluster together (490m from anchor A), C is 980m from anchor A → separate cluster
        self::assertCount(2, $markers, "C must not chain into A's cluster: anchor-based check only, not pairwise");
        self::assertCount(2, $markers[0]['food'], 'A and B should be in the same cluster');
        self::assertCount(1, $markers[1]['food'], 'C must be isolated in its own cluster');
    }
}
