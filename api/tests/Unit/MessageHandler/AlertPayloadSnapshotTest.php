<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Accommodation\CandidateRanker;
use App\Accommodation\SeasonalityCheckerInterface;
use App\AccommodationSource\AccommodationSourceInterface;
use App\AccommodationSource\AccommodationSourceRegistry;
use App\Analyzer\AnalyzerRegistryInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\HourlyWeatherSlot;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\CulturalPoiSource\CulturalPoiSourceInterface;
use App\CulturalPoiSource\CulturalPoiSourceRegistry;
use App\Engine\RiderTimeEstimatorInterface;
use App\Enum\AlertCode;
use App\Enum\AlertType;
use App\Geo\GeometryBasedDistributor;
use App\Geo\GeometryDistributorInterface;
use App\Geo\HaversineDistance;
use App\Geo\NearbyNameDeduplicator;
use App\Mapper\EventArrayMapper;
use App\Mapper\StageArrayMapper;
use App\Mercure\StagePayloadMapper;
use App\Message\AnalyzeTerrain;
use App\Message\AnalyzeWind;
use App\Message\CheckBikeShops;
use App\Message\CheckBorderCrossing;
use App\Message\CheckCalendar;
use App\Message\CheckCulturalPois;
use App\Message\CheckFerries;
use App\Message\CheckFords;
use App\Message\CheckHealthServices;
use App\Message\CheckRailwayStations;
use App\Message\CheckWaterPoints;
use App\Message\ScanAccommodations;
use App\Message\ScanPois;
use App\MessageHandler\AnalyzeTerrainHandler;
use App\MessageHandler\AnalyzeWindHandler;
use App\MessageHandler\CheckBikeShopsHandler;
use App\MessageHandler\CheckBorderCrossingHandler;
use App\MessageHandler\CheckCalendarHandler;
use App\MessageHandler\CheckCulturalPoisHandler;
use App\MessageHandler\CheckFerriesHandler;
use App\MessageHandler\CheckFordsHandler;
use App\MessageHandler\CheckHealthServicesHandler;
use App\MessageHandler\CheckRailwayStationsHandler;
use App\MessageHandler\CheckWaterPointsHandler;
use App\MessageHandler\ScanAccommodationsHandler;
use App\MessageHandler\ScanPoisHandler;
use App\Osm\AdminBoundaryRepositoryInterface;
use App\Osm\BikeShopRepositoryInterface;
use App\Osm\FerryRepositoryInterface;
use App\Osm\FordRepositoryInterface;
use App\Osm\HealthServiceRepositoryInterface;
use App\Osm\RailwayStationRepositoryInterface;
use App\Osm\WaterPointRepositoryInterface;
use App\Osm\WaysRepositoryInterface;
use App\Poi\PoiLabelResolver;
use App\Poi\PoiSourceInterface;
use App\Poi\PoiSourceRegistry;
use App\Poi\ResupplyBuilder;
use App\Poi\SupplyTimelineBuilder;
use App\Repository\TransientTripPointsStoreInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Weather\WeatherForecastSerializer;
use App\Tests\Unit\AlertMessageTestTrait;
use App\Tests\Unit\AlertPayloadSnapshotTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Characterisation of every stage alert producer: what each one persists and publishes,
 * byte for byte. The fixtures under `tests/fixtures/alert-payloads/` are the contract.
 */
final class AlertPayloadSnapshotTest extends TestCase
{
    use AlertMessageTestTrait;
    use AlertPayloadSnapshotTrait;

    private const string TRIP = 'trip-1';

    #[Test]
    public function ferries(): void
    {
        $stages = [$this->stage(1, 47.0, -2.0), $this->stage(2, 47.1, -2.1)];
        $calls = [
            [
                ['name' => 'Le Passage du Gois', 'lat' => 47.05, 'lon' => -2.05],
                ['name' => 'Le Passage du Gois', 'lat' => 47.06, 'lon' => -2.06],
                ['name' => null, 'lat' => 47.07, 'lon' => -2.07],
            ],
            [],
        ];
        $repository = $this->createStub(FerryRepositoryInterface::class);
        $repository->method('findNearStage')->willReturnCallback(static function () use (&$calls): array {
            return array_shift($calls) ?? [];
        });

        $persisted = [];
        $published = [];
        $handler = new CheckFerriesHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests(),
            $this->recordingStageStore($stages, $persisted),
            $repository,
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
        $handler(new CheckFerries(self::TRIP));

        $this->assertAlertPayloadSnapshot('ferries', $stages, $persisted, $published);
    }

    #[Test]
    public function fords(): void
    {
        $stages = [$this->stage(1, 47.0, -2.0), $this->stage(2, 47.1, -2.1)];
        $stages[1]->weather = $this->weather(precipitationProbability: 80);
        $repository = $this->createStub(FordRepositoryInterface::class);
        $repository->method('findNearStage')->willReturn([
            ['name' => 'Gué du Moulin', 'lat' => 47.05, 'lon' => -2.05],
            ['name' => null, 'lat' => 47.06, 'lon' => -2.06],
        ]);

        $persisted = [];
        $published = [];
        $handler = new CheckFordsHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests(),
            $this->recordingStageStore($stages, $persisted),
            $repository,
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
        $handler(new CheckFords(self::TRIP));

        $this->assertAlertPayloadSnapshot('fords', $stages, $persisted, $published);
    }

    #[Test]
    public function railwayStations(): void
    {
        // Stage 1 ends next to a station; stage 2 is far from every station.
        $stages = [$this->stage(1, 48.0, 2.0, 48.5, 2.5), $this->stage(2, 44.0, 4.0, 44.5, 4.5)];

        [$persisted, $published] = $this->runRailwayStations($stages, [
            ['name' => 'Gare A', 'category' => 'station', 'lat' => 48.51, 'lon' => 2.51],
            ['name' => 'Gare B', 'category' => 'station', 'lat' => 46.0, 'lon' => 3.0],
        ]);

        $this->assertAlertPayloadSnapshot('railway-stations', $stages, $persisted, $published);
    }

    #[Test]
    public function railwayStationsWhenNoneIsFound(): void
    {
        $stages = [$this->stage(1, 48.0, 2.0, 48.5, 2.5)];

        [$persisted, $published] = $this->runRailwayStations($stages, []);

        $this->assertAlertPayloadSnapshot('railway-stations-none', $stages, $persisted, $published);
    }

    #[Test]
    public function healthServices(): void
    {
        $stages = [$this->stage(1, 48.0, 2.0, 48.5, 2.5), $this->stage(2, 44.0, 4.0, 44.5, 4.5)];
        $repository = $this->createStub(HealthServiceRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturn([
            ['name' => 'Pharmacie', 'category' => 'pharmacy', 'lat' => 48.25, 'lon' => 2.25],
        ]);

        $persisted = [];
        $published = [];
        $handler = new CheckHealthServicesHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests(),
            $this->recordingStageStore($stages, $persisted),
            $this->createStub(TransientTripPointsStoreInterface::class),
            $repository,
            new HaversineDistance(),
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
        $handler(new CheckHealthServices(self::TRIP));

        $this->assertAlertPayloadSnapshot('health-services', $stages, $persisted, $published);
    }

    #[Test]
    public function bikeShops(): void
    {
        // Six stages: the check only applies beyond five. Stage 1 has a repair shop at its
        // midpoint, stage 2 only a sale-only one, the others nothing nearby.
        $stages = [];
        for ($day = 1; $day <= 6; ++$day) {
            $stages[] = $this->stage($day, 40.0 + $day, 2.0, 40.5 + $day, 2.5);
        }

        $repository = $this->createStub(BikeShopRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturn([
            ['name' => 'Vélo Répar', 'lat' => 41.25, 'lon' => 2.25, 'hasRepair' => true],
            ['name' => 'Vélo Vente', 'lat' => 42.25, 'lon' => 2.25, 'hasRepair' => false],
        ]);

        [$persisted, $published] = $this->runBikeShops($stages, $repository);

        $this->assertAlertPayloadSnapshot('bike-shops', $stages, $persisted, $published);
    }

    #[Test]
    public function bikeShopsWhenNoneIsFound(): void
    {
        $stages = [];
        for ($day = 1; $day <= 6; ++$day) {
            $stages[] = $this->stage($day, 40.0 + $day, 2.0, 40.5 + $day, 2.5);
        }

        $repository = $this->createStub(BikeShopRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturn([]);

        [$persisted, $published] = $this->runBikeShops(\array_slice($stages, 0, 6), $repository);

        $this->assertAlertPayloadSnapshot('bike-shops-none', $stages, $persisted, $published);
    }

    #[Test]
    public function waterPoints(): void
    {
        // Two long stages; the only water point sits at the start of the first one.
        $stages = [$this->stage(1, 48.0, 2.0, 48.5, 2.5, 80.0), $this->stage(2, 44.0, 4.0, 44.5, 4.5, 80.0)];
        $repository = $this->createStub(WaterPointRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturn([
            ['name' => 'Fontaine', 'category' => 'drinking_water', 'lat' => 48.0, 'lon' => 2.0],
        ]);

        $persisted = [];
        $published = [];
        $handler = new CheckWaterPointsHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests(),
            $this->recordingStageStore($stages, $persisted),
            $this->createStub(TransientTripPointsStoreInterface::class),
            $repository,
            new GeometryBasedDistributor(new HaversineDistance()),
            new HaversineDistance(),
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
        $handler(new CheckWaterPoints(self::TRIP));

        $this->assertAlertPayloadSnapshot('water-points', $stages, $persisted, $published);
    }

    #[Test]
    public function culturalPois(): void
    {
        $stages = [$this->stage(1, 48.0, 2.0, 48.5, 2.5)];
        $source = new readonly class implements CulturalPoiSourceInterface {
            public function fetchForStages(array $stageGeometries, int $radiusMeters): array
            {
                return [
                    [
                        'name' => 'Château de Test', 'type' => 'castle', 'lat' => 48.26, 'lon' => 2.25,
                        'osmType' => 'way', 'osmId' => 42, 'openingHours' => 'Mo-Su 10:00-18:00',
                        'website' => 'https://example.org', 'estimatedPrice' => 12.5,
                        'description' => 'A castle.', 'wikidataId' => 'Q1', 'source' => 'osm',
                        'imageUrl' => 'https://example.org/c.jpg', 'wikipediaUrl' => 'https://en.wikipedia.org/wiki/C',
                    ],
                    [
                        'name' => null, 'type' => 'museum', 'lat' => 48.3, 'lon' => 2.31,
                        'osmType' => null, 'osmId' => null, 'openingHours' => null, 'website' => null,
                        'estimatedPrice' => null, 'description' => null, 'wikidataId' => null,
                        'source' => 'datatourisme', 'imageUrl' => null, 'wikipediaUrl' => null,
                    ],
                ];
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function getName(): string
            {
                return 'fake';
            }
        };

        $persisted = [];
        $published = [];
        $handler = new CheckCulturalPoisHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests(),
            $this->recordingStageStore($stages, $persisted),
            new CulturalPoiSourceRegistry([$source], new NearbyNameDeduplicator(new HaversineDistance())),
            new GeometryBasedDistributor(new HaversineDistance()),
            new HaversineDistance(),
            new PoiLabelResolver($this->createAlertTranslator()),
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
        $handler(new CheckCulturalPois(self::TRIP));

        $this->assertAlertPayloadSnapshot('cultural-pois', $stages, $persisted, $published);
    }

    #[Test]
    public function borderCrossing(): void
    {
        $stages = [$this->stage(1, 48.0, 2.0, 48.5, 2.5), $this->stage(2, 48.5, 2.5, 49.0, 3.0)];
        $boundaries = $this->createStub(AdminBoundaryRepositoryInterface::class);
        $boundaries->method('findCountryCodeAt')->willReturnCallback(
            static fn (float $lat): string => $lat >= 49.0 ? 'BE' : 'FR',
        );

        $persisted = [];
        $published = [];
        $handler = new CheckBorderCrossingHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests(),
            $this->recordingStageStore($stages, $persisted),
            $boundaries,
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
        $handler(new CheckBorderCrossing(self::TRIP));

        $this->assertAlertPayloadSnapshot('border-crossing', $stages, $persisted, $published);
    }

    #[Test]
    public function calendar(): void
    {
        // 2026-07-14 is Bastille Day, 2026-07-19 a Sunday.
        $stages = [];
        for ($day = 1; $day <= 6; ++$day) {
            $stages[] = $this->stage($day, 48.0, 2.0, 48.5, 2.5);
        }

        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-07-14');
        $boundaries = $this->createStub(AdminBoundaryRepositoryInterface::class);
        $boundaries->method('findCountryCodeAt')->willReturn('FR');

        $persisted = [];
        $published = [];
        $handler = new CheckCalendarHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests($request),
            $this->recordingStageStore($stages, $persisted),
            $boundaries,
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
        $handler(new CheckCalendar(self::TRIP));

        $this->assertAlertPayloadSnapshot('calendar', $stages, $persisted, $published);
    }

    #[Test]
    public function weatherRules(): void
    {
        // Stage 1 rides into the wind and trips every hourly rule; stage 2 has poor comfort
        // only; stage 3 has no forecast.
        $stages = [$this->stage(1, 48.0, 2.0), $this->stage(2, 48.1, 2.1), $this->stage(3, 48.2, 2.2)];
        $stages[0]->weather = $this->weather(
            windSpeed: 30.0,
            relativeWind: WeatherForecast::RELATIVE_WIND_HEADWIND,
            comfortIndex: 30,
            apparentTempMin: -1.0,
            apparentTempMax: 34.0,
            windGusts: 55.0,
            precipitationMm: 12.0,
            hourly: [new HourlyWeatherSlot(9, 20.0, 19.0, 12.0, 80, 15.0, 55.0, 0, WeatherForecast::RELATIVE_WIND_HEADWIND, 61)],
        );
        $stages[1]->weather = $this->weather(windSpeed: 30.0, relativeWind: WeatherForecast::RELATIVE_WIND_HEADWIND, comfortIndex: 20);

        $persisted = [];
        $published = [];
        $handler = new AnalyzeWindHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests(),
            $this->recordingStageStore($stages, $persisted),
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
        $handler(new AnalyzeWind(self::TRIP));

        $this->assertAlertPayloadSnapshot('weather', $stages, $persisted, $published);
    }

    #[Test]
    public function pois(): void
    {
        // Stage 1: long and without any resupply POI (lunch nudge). Stage 2: two restaurants,
        // both closed at the 16:00 passage (timing warning).
        $stages = [$this->stage(1, 48.0, 2.0, 48.5, 2.5, 80.0), $this->stage(2, 44.0, 4.0, 44.5, 4.5, 80.0)];
        $pois = [
            ['name' => 'Le Bistrot', 'category' => 'restaurant', 'lat' => 44.2, 'lon' => 4.2, 'openingHours' => '12:00-14:00', 'website' => null],
            ['name' => 'Chez Paul', 'category' => 'restaurant', 'lat' => 44.3, 'lon' => 4.3, 'openingHours' => '12:00-14:30', 'website' => null],
        ];
        $source = new readonly class ($pois) implements PoiSourceInterface {
            /** @param list<array{name: string, category: string, lat: float, lon: float, openingHours: string, website: null}> $pois */
            public function __construct(private array $pois)
            {
            }

            public function fetchInCorridor(array $route, int $radiusMeters): array
            {
                return array_map(static fn (array $p): array => $p + ['osmType' => null, 'osmId' => null, 'wikidataId' => null, 'source' => 'osm'], $this->pois);
            }
        };
        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturnOnConsecutiveCalls([1 => $pois], []);
        $estimator = $this->createStub(RiderTimeEstimatorInterface::class);
        $estimator->method('estimateTimeAtDistance')->willReturn(16.0);
        $translator = $this->createAlertTranslator();

        $persisted = [];
        $published = [];
        $handler = new ScanPoisHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests(new TripRequest()),
            $this->recordingStageStore($stages, $persisted),
            $this->createStub(TransientTripPointsStoreInterface::class),
            new PoiSourceRegistry([$source], new NearbyNameDeduplicator(new HaversineDistance())),
            $this->createStub(WaterPointRepositoryInterface::class),
            $distributor,
            new SupplyTimelineBuilder(new HaversineDistance()),
            new ResupplyBuilder(),
            new PoiLabelResolver($translator),
            $estimator,
            new StageArrayMapper(new WeatherForecastSerializer(), new EventArrayMapper()),
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
        $handler(new ScanPois(self::TRIP));

        $this->assertAlertPayloadSnapshot('pois', $stages, $persisted, $this->eventsOfType($published, 'pois_scanned'));
    }

    #[Test]
    public function accommodations(): void
    {
        $stages = [$this->stage(1, 48.0, 2.0, 48.5, 2.5)];
        $source = new readonly class implements AccommodationSourceInterface {
            public function fetch(array $endPoints, int $radiusMeters, array $enabledTypes): array
            {
                return [[
                    'name' => 'Camping Fermé', 'type' => 'camp_site', 'lat' => 48.51, 'lon' => 2.51,
                    'priceMin' => 10.0, 'priceMax' => 20.0, 'isExact' => false, 'url' => null,
                    'stars' => null, 'capacity' => null, 'fee' => null, 'tagCount' => 3, 'hasWebsite' => false,
                    'tags' => ['tourism' => 'camp_site', 'opening_hours' => 'Jun-Aug'], 'source' => 'osm',
                    'wikidataId' => null, 'description' => null, 'imageUrl' => null, 'wikipediaUrl' => null,
                    'openingHours' => null, 'phone' => null, 'osmType' => 'node', 'osmId' => 7,
                ]];
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function getName(): string
            {
                return 'fake';
            }
        };
        $seasonality = $this->createStub(SeasonalityCheckerInterface::class);
        $seasonality->method('isLikelyOpen')->willReturn(false);
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-01-10');

        $persisted = [];
        $published = [];
        $handler = new ScanAccommodationsHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests($request),
            $this->recordingStageStore($stages, $persisted),
            new AccommodationSourceRegistry([$source], new NearbyNameDeduplicator(new HaversineDistance())),
            new HaversineDistance(),
            new GeometryBasedDistributor(new HaversineDistance()),
            $seasonality,
            new CandidateRanker(),
            new StageArrayMapper(new WeatherForecastSerializer(), new EventArrayMapper()),
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
        $handler(new ScanAccommodations(self::TRIP));

        $published = array_map(
            static fn (array $event): array => ['type' => $event['type'], 'alerts' => $event['data']['alerts'] ?? null],
            $this->eventsOfType($published, 'accommodations_found'),
        );
        $this->assertAlertPayloadSnapshot('accommodations', $stages, $persisted, $published);
    }

    /**
     * The reference shape: terrain analyzers already build typed alerts, serialised through
     * the stage mapper. An `auto_fix` action is dropped on the way (issue #397).
     */
    #[Test]
    public function terrain(): void
    {
        $stages = [$this->stage(1, 48.0, 2.0, 48.5, 2.5)];
        $registry = $this->createStub(AnalyzerRegistryInterface::class);
        $registry->method('analyze')->willReturn([
            new Alert(
                code: AlertCode::STEEP_GRADIENT,
                type: AlertType::WARNING,
                messageKey: 'alert.steep_gradient.warning',
                parameters: ['%gradient%' => 12.5],
                parameterFormats: ['%gradient%' => 'decimal'],
                lat: 48.2,
                lon: 2.2,
                action: new AlertAction(AlertActionKind::NAVIGATE, 'alert.steep_gradient.action', ['lat' => 48.2, 'lon' => 2.2]),
            ),
            new Alert(
                code: AlertCode::CONTINUITY_GAP_CRITICAL,
                type: AlertType::CRITICAL,
                messageKey: 'alert.continuity.critical',
                action: new AlertAction(AlertActionKind::AUTO_FIX, 'alert.continuity.action'),
            ),
        ]);
        $ways = $this->createStub(WaysRepositoryInterface::class);
        $ways->method('findInCorridor')->willReturn([]);
        $renderer = $this->createAlertRenderer();

        $persisted = [];
        $published = [];
        $handler = new AnalyzeTerrainHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests(new TripRequest()),
            $this->recordingStageStore($stages, $persisted),
            $this->createStub(TransientTripPointsStoreInterface::class),
            $registry,
            $ways,
            new GeometryBasedDistributor(new HaversineDistance()),
            new StagePayloadMapper(new StageArrayMapper(new WeatherForecastSerializer(), new EventArrayMapper()), $renderer),
            $this->createStub(MessageBusInterface::class),
            $renderer,
        );
        $handler(new AnalyzeTerrain(self::TRIP));

        $this->assertAlertPayloadSnapshot('terrain', $stages, $persisted, $published);
    }

    /**
     * @param list<Stage>                                                                  $stages
     * @param list<array{name: ?string, category: string, lat: float, lon: float}> $stations
     *
     * @return array{0: array<string, mixed>, 1: list<array{type: string, data: array<string, mixed>}>}
     */
    private function runRailwayStations(array $stages, array $stations): array
    {
        $repository = $this->createStub(RailwayStationRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturn($stations);

        $persisted = [];
        $published = [];
        $handler = new CheckRailwayStationsHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests(),
            $this->recordingStageStore($stages, $persisted),
            $repository,
            new HaversineDistance(),
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
        $handler(new CheckRailwayStations(self::TRIP));

        return [$persisted, $published];
    }

    /**
     * @param list<Stage> $stages
     *
     * @return array{0: array<string, mixed>, 1: list<array{type: string, data: array<string, mixed>}>}
     */
    private function runBikeShops(array $stages, BikeShopRepositoryInterface $repository): array
    {
        $persisted = [];
        $published = [];
        $handler = new CheckBikeShopsHandler(
            $this->tracker(),
            $this->recordingPublisher($published),
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $this->requests(),
            $this->recordingStageStore($stages, $persisted),
            $this->createStub(TransientTripPointsStoreInterface::class),
            $repository,
            new HaversineDistance(),
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
        $handler(new CheckBikeShops(self::TRIP));

        return [$persisted, $published];
    }

    /**
     * @param list<array{type: string, data: array<string, mixed>}> $published
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function eventsOfType(array $published, string $type): array
    {
        return array_values(array_filter($published, static fn (array $event): bool => $type === $event['type']));
    }

    private function tracker(): ComputationTrackerInterface
    {
        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $tracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'settled' => 0, 'total' => 1]);

        return $tracker;
    }

    private function requests(?TripRequest $request = null): TripRequestRepositoryInterface
    {
        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $repository->method('getLocale')->willReturn('en');
        $repository->method('getRequest')->willReturn($request);

        return $repository;
    }

    private function stage(int $day, float $lat, float $lon, ?float $endLat = null, ?float $endLon = null, float $distance = 60.0): Stage
    {
        $endLat ??= $lat + 0.1;
        $endLon ??= $lon + 0.1;

        $geometry = [];
        for ($i = 0; $i <= 4; ++$i) {
            $geometry[] = new Coordinate($lat + ($endLat - $lat) * $i / 4, $lon + ($endLon - $lon) * $i / 4);
        }

        return new Stage(
            tripId: self::TRIP,
            dayNumber: $day,
            distance: $distance,
            elevation: 100.0,
            startPoint: new Coordinate($lat, $lon),
            endPoint: new Coordinate($endLat, $endLon),
            geometry: $geometry,
        );
    }

    /**
     * @param list<HourlyWeatherSlot> $hourly
     */
    private function weather(
        float $windSpeed = 10.0,
        string $relativeWind = WeatherForecast::RELATIVE_WIND_CROSSWIND,
        int $comfortIndex = 80,
        int $precipitationProbability = 10,
        float $apparentTempMin = 10.0,
        float $apparentTempMax = 20.0,
        float $windGusts = 10.0,
        float $precipitationMm = 0.0,
        array $hourly = [],
    ): WeatherForecast {
        return new WeatherForecast(
            icon: 'sunny',
            description: 'Clear',
            tempMin: 10.0,
            tempMax: 20.0,
            windSpeed: $windSpeed,
            windDirection: 'N',
            precipitationProbability: $precipitationProbability,
            humidity: 60,
            comfortIndex: $comfortIndex,
            relativeWindDirection: $relativeWind,
            apparentTempMin: $apparentTempMin,
            apparentTempMax: $apparentTempMax,
            windGusts: $windGusts,
            precipitationMm: $precipitationMm,
            hourly: $hourly,
        );
    }
}
