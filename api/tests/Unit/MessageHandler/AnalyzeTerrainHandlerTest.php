<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Analyzer\AnalyzerRegistryInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertType;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeTerrain;
use App\MessageHandler\AnalyzeTerrainHandler;
use App\Osm\WaysRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\TripCompletionGate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

final class AnalyzeTerrainHandlerTest extends TestCase
{
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
                self::assertSame(100, $radiusMeters, 'findInCorridor must use the 100 m ways corridor');

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
        $messageBus = $this->createStub(MessageBusInterface::class);

        $handler = new AnalyzeTerrainHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $analyzerRegistry,
            $waysRepository,
            $distributor,
            $messageBus,
        );
        $handler->setCompletionGate(new TripCompletionGate($computationTracker, $publisher, $messageBus));

        return $handler;
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

        $alert = new Alert(type: AlertType::WARNING, message: 'Unpaved road detected', lat: 48.0, lon: 2.0);
        $analyzerRegistry = $this->createStub(AnalyzerRegistryInterface::class);
        $analyzerRegistry->method('analyze')->willReturn([$alert]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::TERRAIN_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alertsByStage'][0] ?? [];

                    return 1 === \count($alerts)
                        && 'warning' === $alerts[0]['type']
                        && 'Unpaved road detected' === $alerts[0]['message'];
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
                self::assertSame(100, $radiusMeters);
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
