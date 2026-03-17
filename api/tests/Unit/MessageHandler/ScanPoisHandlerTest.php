<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Engine\RiderTimeEstimatorInterface;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanPois;
use App\MessageHandler\ScanPoisHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => $id.': '.json_encode($params),
        );

        return new ScanPoisHandler(
            $computationTracker,
            $publisher,
            $tripStateManager,
            $scanner,
            $queryBuilder,
            $distributor,
            $haversine,
            $riderTimeEstimator,
            $translator,
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
     * @return array{QueryBuilderInterface&\PHPUnit\Framework\MockObject\Stub, GeoDistanceInterface&\PHPUnit\Framework\MockObject\Stub, RiderTimeEstimatorInterface&\PHPUnit\Framework\MockObject\Stub}
     */
    private function createDefaultStubs(): array
    {
        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildPoiQuery')->willReturn('query');

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
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['amenity' => 'restaurant', 'name' => 'Le Bistrot']],
                ['lat' => 48.3, 'lon' => 2.3, 'tags' => ['amenity' => 'restaurant', 'name' => 'Chez Paul']],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([
            0 => [
                ['name' => 'Le Bistrot', 'category' => 'restaurant', 'lat' => 48.2, 'lon' => 2.2],
                ['name' => 'Chez Paul', 'category' => 'restaurant', 'lat' => 48.3, 'lon' => 2.3],
            ],
        ]);

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
        $data = array_values($poisScannedEvents)[0]['payload'];
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
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['amenity' => 'restaurant', 'name' => 'Le Bistrot']],
                ['lat' => 48.3, 'lon' => 2.3, 'tags' => ['shop' => 'supermarket', 'name' => 'Carrefour']],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([
            0 => [
                ['name' => 'Le Bistrot', 'category' => 'restaurant', 'lat' => 48.2, 'lon' => 2.2],
                ['name' => 'Carrefour', 'category' => 'supermarket', 'lat' => 48.3, 'lon' => 2.3],
            ],
        ]);

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
        $data = array_values($poisScannedEvents)[0]['payload'];
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
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['tourism' => 'viewpoint', 'name' => 'Belvedere']],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([
            0 => [
                ['name' => 'Belvedere', 'category' => 'viewpoint', 'lat' => 48.2, 'lon' => 2.2],
            ],
        ]);

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
        $data = array_values($poisScannedEvents)[0]['payload'];
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
        $scanner->method('query')->willReturn(['elements' => []]);

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
        $data = array_values($poisScannedEvents)[0]['payload'];
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
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['shop' => 'bakery', 'name' => 'Boulangerie']],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([
            0 => [
                ['name' => 'Boulangerie', 'category' => 'bakery', 'lat' => 48.2, 'lon' => 2.2],
            ],
        ]);

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
        $data = array_values($poisScannedEvents)[0]['payload'];
        $alerts = $data['alerts'] ?? [];
        self::assertFalse(
            array_any($alerts, static fn (array $a): bool => 'warning' === $a['type']),
            'Expected no timing warning since bakery is open at 10:00',
        );
    }
}
