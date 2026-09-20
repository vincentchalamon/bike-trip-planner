<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Tests\Unit\AlertMessageTestTrait;
use App\Analyzer\AnalyzerRegistryInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertCode;
use App\Enum\AlertType;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\StagePayloadMapper;
use App\Mercure\TripUpdatePublisherInterface;
use App\Weather\WeatherForecastSerializer;
use App\Message\AnalyzeTerrain;
use App\MessageHandler\AnalyzeTerrainHandler;
use App\Osm\WaysRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

final class AnalyzeTerrainHandlerTest extends TestCase
{
    use AlertMessageTestTrait;

    private function createStage(string $tripId = 'trip-1', int $dayNumber = 1): Stage
    {
        return new Stage(
            tripId: $tripId,
            dayNumber: $dayNumber,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
            geometry: [
                new Coordinate(48.0, 2.0),
                new Coordinate(48.25, 2.25),
                new Coordinate(48.5, 2.5),
            ],
        );
    }

    /**
     * @param list<array{lat: float, lon: float, surface: string, highway: string, cycleway: string, 'cycleway:right': string, 'cycleway:left': string, 'cycleway:both': string, bicycle: string, maxspeed: string, length: float}> $ways
     */
    private function waysRepository(array $ways): WaysRepositoryInterface
    {
        $repository = $this->createStub(WaysRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturnCallback(
            static function (array $route, int $radiusMeters) use ($ways): array {
                self::assertSame(20, $radiusMeters, 'findInCorridor must use the 20 m ways corridor');

                return $ways;
            },
        );

        return $repository;
    }

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        AnalyzerRegistryInterface $analyzerRegistry,
        TripUpdatePublisherInterface $publisher,
        WaysRepositoryInterface $waysRepository,
        GeometryDistributorInterface $distributor,
    ): AnalyzeTerrainHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        return new AnalyzeTerrainHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $analyzerRegistry,
            $waysRepository,
            $distributor,
            new StagePayloadMapper(new WeatherForecastSerializer(), $this->createAlertRenderer()),
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
    }

    #[Test]
    public function noStagesReturnsEarly(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $handler = $this->createHandler(
            $tripStateManager,
            $this->createStub(AnalyzerRegistryInterface::class),
            $publisher,
            $this->createStub(WaysRepositoryInterface::class),
            $this->createStub(GeometryDistributorInterface::class),
        );

        $handler(new AnalyzeTerrain('trip-1'));
    }

    #[Test]
    public function passesOsmWaysInContextToAnalyzers(): void
    {
        $stage = $this->createStage();
        $tripRequest = new TripRequest();
        $tripRequest->ebikeMode = false;

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn($tripRequest);
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.0, 'lon' => 2.0, 'ele' => 0.0],
            ['lat' => 48.5, 'lon' => 2.5, 'ele' => 0.0],
        ]);

        $waysRepository = $this->waysRepository([
            ['lat' => 48.1, 'lon' => 2.1, 'surface' => 'asphalt', 'highway' => 'primary', 'cycleway' => '', 'cycleway:right' => '', 'cycleway:left' => '', 'cycleway:both' => '', 'bicycle' => '', 'maxspeed' => '', 'length' => 1000.0],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([
            0 => [
                ['lat' => 48.1, 'lon' => 2.1, 'surface' => 'asphalt', 'highway' => 'primary', 'length' => 1000.0],
            ],
        ]);

        $capturedContext = null;
        $analyzerRegistry = $this->createMock(AnalyzerRegistryInterface::class);
        $analyzerRegistry->expects($this->once())
            ->method('analyze')
            ->willReturnCallback(function (Stage $stage, array $context) use (&$capturedContext): array {
                $capturedContext = $context;

                return [];
            });

        $handler = $this->createHandler(
            $tripStateManager,
            $analyzerRegistry,
            $this->createStub(TripUpdatePublisherInterface::class),
            $waysRepository,
            $distributor,
        );

        $handler(new AnalyzeTerrain('trip-1'));

        $this->assertIsArray($capturedContext);
        $this->assertArrayHasKey('osmWays', $capturedContext);
        $this->assertCount(1, $capturedContext['osmWays']);
        $this->assertSame('primary', $capturedContext['osmWays'][0]['highway']);
        $this->assertSame('asphalt', $capturedContext['osmWays'][0]['surface']);
        $this->assertSame(1000.0, $capturedContext['osmWays'][0]['length']);
    }

    #[Test]
    public function publishesTerrainAlertsFromAnalyzers(): void
    {
        $stage = $this->createStage();
        $tripRequest = new TripRequest();
        $tripRequest->ebikeMode = false;

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('fr');
        $tripStateManager->method('getRequest')->willReturn($tripRequest);
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.0, 'lon' => 2.0, 'ele' => 0.0],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        $alert = new Alert(code: AlertCode::SURFACE_ROUGH, type: AlertType::WARNING, messageKey: 'alert.surface.warning', lat: 48.0, lon: 2.0);
        $analyzerRegistry = $this->createStub(AnalyzerRegistryInterface::class);
        $analyzerRegistry->method('analyze')->willReturn([$alert]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::TERRAIN_ALERTS,
                // Keyed by identity, never by position: this assertion used to read
                // `['alertsByStage'][0]`, so it kept passing after ADR-066 moved the client
                // to resolve by identity — and the live terrain alerts were silently
                // dropped for want of a matching key.
                $this->callback(static function (array $data) use ($stage): bool {
                    self::assertArrayNotHasKey(0, $data['alertsByStage']);
                    $alerts = $data['alertsByStage'][$stage->id] ?? [];

                    // The key travels to the database, the rendered sentence to the page.
                    return 1 === \count($alerts)
                        && 'warning' === $alerts[0]['type']
                        && 'alert.surface.warning' === $alerts[0]['messageKey'];
                }),
            );

        $handler = $this->createHandler(
            $tripStateManager,
            $analyzerRegistry,
            $publisher,
            $this->waysRepository([]),
            $distributor,
        );

        $handler(new AnalyzeTerrain('trip-1'));
    }

    #[Test]
    public function publishedAlertsCarryCoordinatesAndWiredActionsOnly(): void
    {
        $stage = $this->createStage();
        $tripRequest = new TripRequest();
        $tripRequest->ebikeMode = false;

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('fr');
        $tripStateManager->method('getRequest')->willReturn($tripRequest);
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.0, 'lon' => 2.0, 'ele' => 0.0],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        $navigateAlert = new Alert(
            code: AlertCode::CONTINUITY_GAP_CRITICAL,
            type: AlertType::CRITICAL,
            messageKey: 'alert.continuity.critical',
            lat: 48.1,
            lon: 2.2,
            action: new AlertAction(
                kind: AlertActionKind::NAVIGATE,
                labelKey: 'alert.continuity.action',
                payload: ['lat' => 48.1, 'lon' => 2.2],
            ),
        );
        // auto_fix is not wired in the frontend (#397): it must not be published,
        // otherwise a dead disabled button shows up.
        $autoFixAlert = new Alert(
            code: AlertCode::ELEVATION_GAIN,
            type: AlertType::WARNING,
            messageKey: 'alert.elevation.warning',
            lat: 48.3,
            lon: 2.4,
            action: new AlertAction(
                kind: AlertActionKind::AUTO_FIX,
                labelKey: 'alert.elevation.action',
                payload: ['splitAt' => 40.0],
            ),
        );

        $analyzerRegistry = $this->createStub(AnalyzerRegistryInterface::class);
        $analyzerRegistry->method('analyze')->willReturn([$navigateAlert, $autoFixAlert]);

        $published = null;
        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->willReturnCallback(function (string $tripId, MercureEventType $type, array $data) use (&$published): void {
                self::assertSame(MercureEventType::TERRAIN_ALERTS, $type);
                $published = $data;
            });

        $handler = $this->createHandler(
            $tripStateManager,
            $analyzerRegistry,
            $publisher,
            $this->waysRepository([]),
            $distributor,
        );

        $handler(new AnalyzeTerrain('trip-1'));

        $this->assertIsArray($published);
        $this->assertArrayNotHasKey(0, $published['alertsByStage']);
        $alerts = $published['alertsByStage'][$stage->id];
        $this->assertCount(2, $alerts);

        $this->assertSame(48.1, $alerts[0]['lat']);
        $this->assertSame(2.2, $alerts[0]['lon']);
        // The published copy is rendered: the key travels to the database, the sentence to
        // the open page (ADR-069).
        self::assertSame('navigate', $alerts[0]['action']['kind']);
        self::assertSame('alert.continuity.action', $alerts[0]['action']['labelKey']);
        // Rendered in the trip's language, which this trip declares as French.
        self::assertSame('Voir la discontinuité sur la carte', $alerts[0]['action']['label']);
        self::assertSame(['lat' => 48.1, 'lon' => 2.2], $alerts[0]['action']['payload']);

        $this->assertSame(48.3, $alerts[1]['lat']);
        $this->assertSame(2.4, $alerts[1]['lon']);
        $this->assertArrayNotHasKey('action', $alerts[1]);
    }

    #[Test]
    public function usesStageGeometryRouteWhenNoDecimatedPoints(): void
    {
        $stage = $this->createStage();
        $tripRequest = new TripRequest();
        $tripRequest->ebikeMode = false;

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getRequest')->willReturn($tripRequest);
        $tripStateManager->method('getDecimatedPoints')->willReturn(null);

        // No decimated points → the corridor route is built from the stage geometry.
        $waysRepository = $this->createStub(WaysRepositoryInterface::class);
        $waysRepository->method('findInCorridor')->willReturnCallback(
            static function (array $route, int $radiusMeters): array {
                self::assertSame(20, $radiusMeters);
                self::assertSame([
                    ['lat' => 48.0, 'lon' => 2.0],
                    ['lat' => 48.25, 'lon' => 2.25],
                    ['lat' => 48.5, 'lon' => 2.5],
                ], $route);

                return [];
            },
        );

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        $analyzerRegistry = $this->createStub(AnalyzerRegistryInterface::class);
        $analyzerRegistry->method('analyze')->willReturn([]);

        $handler = $this->createHandler(
            $tripStateManager,
            $analyzerRegistry,
            $this->createStub(TripUpdatePublisherInterface::class),
            $waysRepository,
            $distributor,
        );

        $handler(new AnalyzeTerrain('trip-1'));
    }
}
