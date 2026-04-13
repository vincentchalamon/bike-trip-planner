<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckCulturalPois;
use App\MessageHandler\CheckCulturalPoisHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CheckCulturalPoisHandlerTest extends TestCase
{
    private function createStage(int $dayNumber, bool $isRestDay = false): Stage
    {
        $coord = new Coordinate(lat: 48.0, lon: 2.0);

        return new Stage(
            tripId: 'trip-1',
            dayNumber: $dayNumber,
            distance: $isRestDay ? 0.0 : 80.0,
            elevation: 0.0,
            startPoint: $coord,
            endPoint: new Coordinate(lat: 48.5, lon: 2.5),
            geometry: [
                new Coordinate(lat: 48.0, lon: 2.0),
                new Coordinate(lat: 48.2, lon: 2.2),
                new Coordinate(lat: 48.5, lon: 2.5),
            ],
            isRestDay: $isRestDay,
        );
    }

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        ScannerInterface $scanner,
        QueryBuilderInterface $queryBuilder,
        GeoDistanceInterface $haversine,
        ?GeometryDistributorInterface $distributor = null,
    ): CheckCulturalPoisHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => $id.': '.json_encode($params),
        );

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $distributor ??= $this->createStub(GeometryDistributorInterface::class);

        return new CheckCulturalPoisHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $scanner,
            $queryBuilder,
            $distributor,
            $haversine,
            $translator,
        );
    }

    /**
     * @param list<Stage>|null $stages
     */
    private function createTripStateManager(?array $stages): TripRequestRepositoryInterface
    {
        $manager = $this->createStub(TripRequestRepositoryInterface::class);
        $manager->method('getStages')->willReturn($stages);
        $manager->method('getLocale')->willReturn('en');

        return $manager;
    }

    #[Test]
    public function nullStagesYieldsNoPublish(): void
    {
        $tripStateManager = $this->createTripStateManager(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $scanner = $this->createStub(ScannerInterface::class);
        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckCulturalPois('trip-1'));
    }

    #[Test]
    public function restDayStageIsSkippedAndScannerIsNeverCalled(): void
    {
        $restDay = $this->createStage(1, true);
        $tripStateManager = $this->createTripStateManager([$restDay]);

        $scanner = $this->createMock(ScannerInterface::class);
        $scanner->expects($this->never())->method('query');

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckCulturalPois('trip-1'));

        $alertEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::CULTURAL_POI_ALERTS === $e['type']);
        self::assertCount(1, $alertEvents);
        $event = array_first($alertEvents);
        self::assertNotNull($event);
        self::assertSame([], $event['payload']['alerts']);
    }

    #[Test]
    public function unknownTagsYieldNoAlert(): void
    {
        $stage = $this->createStage(1);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                // tourism=hotel is not a notable type
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['tourism' => 'hotel', 'name' => 'Hotel des Alpes']],
                // amenity=parking is unknown
                ['lat' => 48.3, 'lon' => 2.3, 'tags' => ['amenity' => 'parking']],
            ],
        ]);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildCulturalPoiQuery')->willReturn('query');

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(200.0);

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckCulturalPois('trip-1'));

        $alertEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::CULTURAL_POI_ALERTS === $e['type']);
        $event = array_first($alertEvents);
        self::assertNotNull($event);
        self::assertSame([], $event['payload']['alerts']);
    }

    #[Test]
    public function historicValueNotInNotableListIsSkipped(): void
    {
        $stage = $this->createStage(1);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                // historic=milestone is not in NOTABLE_HISTORIC_VALUES
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['historic' => 'milestone', 'name' => 'Old Milestone']],
            ],
        ]);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildCulturalPoiQuery')->willReturn('query');

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(100.0);

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckCulturalPois('trip-1'));

        $alertEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::CULTURAL_POI_ALERTS === $e['type']);
        $event = array_first($alertEvents);
        self::assertNotNull($event);
        self::assertSame([], $event['payload']['alerts']);
    }

    #[Test]
    public function resultsCappedAtThreeAndSortedByProximity(): void
    {
        $stage = $this->createStage(1);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $scanner = $this->createStub(ScannerInterface::class);
        // 4 valid cultural POIs — only the 3 closest should be kept
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.1, 'lon' => 2.1, 'tags' => ['tourism' => 'museum', 'name' => 'Museum A']],
                ['lat' => 48.15, 'lon' => 2.15, 'tags' => ['tourism' => 'museum', 'name' => 'Museum B']],
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['tourism' => 'museum', 'name' => 'Museum C']],
                // Museum D is the farthest — should be excluded
                ['lat' => 48.4, 'lon' => 2.4, 'tags' => ['tourism' => 'museum', 'name' => 'Museum D']],
            ],
        ]);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBatchCulturalPoiQuery')->willReturn('query');

        $haversine = $this->createStub(GeoDistanceInterface::class);
        // Museum D is farthest from all geometry points
        $haversine->method('inMeters')->willReturnCallback(
            static function (float $lat1, float $lon1, float $lat2, float $lon2): float {
                if (abs($lat2 - 48.4) < 0.01) {
                    return 400.0;
                }

                // Museum D — farthest
                if (abs($lat2 - 48.2) < 0.01) {
                    return 300.0;
                }

                // Museum C
                if (abs($lat2 - 48.15) < 0.01) {
                    return 200.0;
                } // Museum B

                return 100.0; // Museum A — closest
            },
        );

        // Distributor returns all 4 POIs assigned to stage 0
        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnCallback(
            static fn (array $items): array => [0 => $items],
        );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine, $distributor);
        $handler(new CheckCulturalPois('trip-1'));

        $alertEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::CULTURAL_POI_ALERTS === $e['type']);
        $event = array_first($alertEvents);
        self::assertNotNull($event);
        $alerts = $event['payload']['alerts'];

        self::assertCount(3, $alerts, 'Only 3 suggestions should be kept (MAX_SUGGESTIONS_PER_STAGE)');
        self::assertSame(100, $alerts[0]['distanceFromRoute'], 'First alert must be the closest POI');

        $names = array_column($alerts, 'poiName');
        self::assertNotContains('Museum D', $names, 'Farthest POI must be excluded');
    }

    #[Test]
    public function notableHistoricValueIsIncluded(): void
    {
        $stage = $this->createStage(1);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.2, 'lon' => 2.2, 'tags' => ['historic' => 'castle', 'name' => 'Castle Rock']],
            ],
        ]);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildBatchCulturalPoiQuery')->willReturn('query');

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(250.0);

        // Distributor returns the single POI assigned to stage 0
        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnCallback(
            static fn (array $items): array => [0 => $items],
        );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine, $distributor);
        $handler(new CheckCulturalPois('trip-1'));

        $alertEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::CULTURAL_POI_ALERTS === $e['type']);
        $event = array_first($alertEvents);
        self::assertNotNull($event);
        $alerts = $event['payload']['alerts'];

        self::assertCount(1, $alerts);
        self::assertSame('castle', $alerts[0]['poiType']);
        self::assertSame('Castle Rock', $alerts[0]['poiName']);
        self::assertSame('nudge', $alerts[0]['type']);
        self::assertSame(250, $alerts[0]['distanceFromRoute']);
    }
}
