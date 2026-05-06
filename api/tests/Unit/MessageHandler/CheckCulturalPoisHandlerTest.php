<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\CulturalPoiSource\CulturalPoiSourceRegistry;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckCulturalPois;
use App\MessageHandler\CheckCulturalPoisHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Wikidata\WikidataEnricherInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
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
        CulturalPoiSourceRegistry $registry,
        GeoDistanceInterface $haversine,
        ?GeometryDistributorInterface $distributor = null,
    ): CheckCulturalPoisHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);
        $computationTracker->method('areAllEnrichmentsCompleted')->willReturn(false);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

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
            $registry,
            $distributor,
            $haversine,
            $translator,
            $this->createStub(WikidataEnricherInterface::class),
            $this->createStub(MessageBusInterface::class),
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

    /**
     * @param list<array<string, mixed>> $pois
     */
    private function makeRegistryWithPois(array $pois): CulturalPoiSourceRegistry
    {
        $registry = $this->createStub(CulturalPoiSourceRegistry::class);
        $registry->method('fetchAllForStages')->willReturn($pois);

        return $registry;
    }

    #[Test]
    public function nullStagesYieldsNoPublish(): void
    {
        $tripStateManager = $this->createTripStateManager(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $registry = $this->makeRegistryWithPois([]);
        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine);
        $handler(new CheckCulturalPois('trip-1'));
    }

    #[Test]
    public function restDayStageIsSkippedAndRegistryIsNeverCalled(): void
    {
        $restDay = $this->createStage(1, true);
        $tripStateManager = $this->createTripStateManager([$restDay]);

        $registry = $this->createMock(CulturalPoiSourceRegistry::class);
        $registry->expects($this->never())->method('fetchAllForStages');

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine);
        $handler(new CheckCulturalPois('trip-1'));

        $alertEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::CULTURAL_POI_ALERTS === $e['type']);
        self::assertCount(1, $alertEvents);
        $event = array_first($alertEvents);
        self::assertNotNull($event);
        self::assertSame([], $event['payload']['alerts']);
    }

    #[Test]
    public function noPoisFromRegistryYieldsEmptyAlerts(): void
    {
        $stage = $this->createStage(1);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $registry = $this->makeRegistryWithPois([]);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
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

        $pois = [
            ['name' => 'Museum A', 'type' => 'museum', 'lat' => 48.1, 'lon' => 2.1, 'source' => 'osm'],
            ['name' => 'Museum B', 'type' => 'museum', 'lat' => 48.15, 'lon' => 2.15, 'source' => 'osm'],
            ['name' => 'Museum C', 'type' => 'museum', 'lat' => 48.2, 'lon' => 2.2, 'source' => 'osm'],
            ['name' => 'Museum D', 'type' => 'museum', 'lat' => 48.4, 'lon' => 2.4, 'source' => 'osm'],
        ];

        $registry = $this->makeRegistryWithPois($pois);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturnCallback(
            static function (float $lat1, float $lon1, float $lat2, float $lon2): float {
                if (abs($lat2 - 48.4) < 0.01) {
                    return 400.0; // Museum D — farthest
                }

                if (abs($lat2 - 48.2) < 0.01) {
                    return 300.0; // Museum C
                }

                if (abs($lat2 - 48.15) < 0.01) {
                    return 200.0; // Museum B
                }

                return 100.0; // Museum A — closest
            },
        );

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnCallback(
            static fn (array $items): array => [0 => $items],
        );

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
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
    public function enrichmentFieldsFromDataTourismeAreIncludedInAlert(): void
    {
        $stage = $this->createStage(1);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $pois = [
            [
                'name' => 'Louvre',
                'type' => 'museum',
                'lat' => 48.8606,
                'lon' => 2.3376,
                'openingHours' => 'Mon–Sat 09:00–18:00',
                'estimatedPrice' => 15.0,
                'description' => 'World-famous art museum.',
                'wikidataId' => 'Q19675',
                'source' => 'datatourisme',
            ],
        ];

        $registry = $this->makeRegistryWithPois($pois);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(200.0);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnCallback(
            static fn (array $items): array => [0 => $items],
        );

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
        $handler(new CheckCulturalPois('trip-1'));

        $alertEvents = array_filter($publishedEvents, static fn (array $e): bool => MercureEventType::CULTURAL_POI_ALERTS === $e['type']);
        $event = array_first($alertEvents);
        self::assertNotNull($event);
        $alerts = $event['payload']['alerts'];

        self::assertCount(1, $alerts);
        self::assertSame('Mon–Sat 09:00–18:00', $alerts[0]['openingHours']);
        self::assertSame(15.0, $alerts[0]['estimatedPrice']);
        self::assertSame('World-famous art museum.', $alerts[0]['description']);
        self::assertSame('Q19675', $alerts[0]['wikidataId']);
        self::assertSame('datatourisme', $alerts[0]['source']);
    }

    #[Test]
    public function osmPoiWithoutEnrichmentFieldsDoesNotIncludeThemInAlert(): void
    {
        $stage = $this->createStage(1);
        $tripStateManager = $this->createTripStateManager([$stage]);

        $pois = [
            [
                'name' => 'Castle Rock',
                'type' => 'castle',
                'lat' => 48.2,
                'lon' => 2.2,
                'openingHours' => null,
                'estimatedPrice' => null,
                'description' => null,
                'wikidataId' => null,
                'source' => 'osm',
            ],
        ];

        $registry = $this->makeRegistryWithPois($pois);

        $publishedEvents = [];
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')
            ->willReturnCallback(static function (string $tripId, MercureEventType $type, array $payload) use (&$publishedEvents): void {
                $publishedEvents[] = ['type' => $type, 'payload' => $payload];
            });

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(250.0);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnCallback(
            static fn (array $items): array => [0 => $items],
        );

        $handler = $this->createHandler($tripStateManager, $publisher, $registry, $haversine, $distributor);
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
        self::assertArrayNotHasKey('openingHours', $alerts[0]);
        self::assertArrayNotHasKey('estimatedPrice', $alerts[0]);
        self::assertArrayNotHasKey('description', $alerts[0]);
        self::assertArrayNotHasKey('wikidataId', $alerts[0]);
    }
}
