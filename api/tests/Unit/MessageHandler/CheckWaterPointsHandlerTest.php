<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckWaterPoints;
use App\MessageHandler\CheckWaterPointsHandler;
use App\Osm\WaterPointRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\TripCompletionGate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CheckWaterPointsHandlerTest extends TestCase
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
        WaterPointRepositoryInterface $waterPointRepository,
        GeometryDistributorInterface $distributor,
        GeoDistanceInterface $haversine,
    ): CheckWaterPointsHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => \sprintf('No water on stage %s for 30+ km.', $params['%stage%'] ?? ''),
        );

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        $messageBus = $this->createStub(MessageBusInterface::class);

        $handler = new CheckWaterPointsHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $waterPointRepository,
            $distributor,
            $haversine,
            $translator,
            $messageBus,
        );
        $handler->setCompletionGate(new TripCompletionGate($computationTracker, $publisher, $messageBus));

        return $handler;
    }

    /**
     * @param list<array{lat: float, lon: float}> $points
     */
    private function waterPointRepository(array $points): WaterPointRepositoryInterface
    {
        // Pin the corridor radius (CheckWaterPointsHandler::CORRIDOR_RADIUS_METERS) so an
        // accidental change is caught. willReturnCallback asserts the argument without the
        // deprecated any()/with() mock expectation, and does not run when (as in
        // noStagesReturnsEarly) the handler returns before querying the repository.
        $repository = $this->createStub(WaterPointRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturnCallback(
            static function (array $route, int $radiusMeters) use ($points): array {
                self::assertSame(2000, $radiusMeters, 'findInCorridor must use the 2 km corridor radius');

                return array_map(
                    static fn (array $point): array => ['name' => null, 'category' => 'drinking_water', 'lat' => $point['lat'], 'lon' => $point['lon']],
                    $points,
                );
            },
        );

        return $repository;
    }

    /**
     * @param list<Stage>|null $stages
     */
    private function tripStateManager(?array $stages): TripRequestRepositoryInterface
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.0, 'lon' => 2.0, 'ele' => 0.0],
            ['lat' => 48.5, 'lon' => 2.5, 'ele' => 0.0],
        ]);

        return $tripStateManager;
    }

    #[Test]
    public function stageWithWaterPointsEmitsNoAlert(): void
    {
        // Stage of 50km with 3 water points evenly spread → no 30km gap
        $tripStateManager = $this->tripStateManager([$this->createStage('trip-1', 1, 50.0)]);

        $waterPointRepository = $this->waterPointRepository([
            ['lat' => 48.1, 'lon' => 2.1],
            ['lat' => 48.3, 'lon' => 2.3],
            ['lat' => 48.4, 'lon' => 2.4],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([
            0 => [
                ['lat' => 48.1, 'lon' => 2.1],
                ['lat' => 48.3, 'lon' => 2.3],
                ['lat' => 48.4, 'lon' => 2.4],
            ],
        ]);

        // Each geometry segment is 10km → cumulative: 0, 10, 20, 30, 40, 50
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(10.0);
        $haversine->method('inMeters')->willReturnCallback(
            static fn (float $lat1, float $lon1, float $lat2, float $lon2): float => ($lat1 === $lat2 && $lon1 === $lon2) ? 0.0 : 10000.0,
        );

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WATER_POINT_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $waterPointRepository, $distributor, $haversine);
        $handler(new CheckWaterPoints('trip-1'));
    }

    #[Test]
    public function longStageWithoutWaterPointEmitsNudge(): void
    {
        $tripStateManager = $this->tripStateManager([$this->createStage('trip-1', 1, 50.0)]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WATER_POINT_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 1 === \count($alerts)
                        && 'nudge' === $alerts[0]['type']
                        && 0 === $alerts[0]['stageIndex']
                        && 1 === $alerts[0]['dayNumber']
                        && null === $alerts[0]['action'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $this->waterPointRepository([]), $distributor, $haversine);
        $handler(new CheckWaterPoints('trip-1'));
    }

    #[Test]
    public function shortStageWithoutWaterPointEmitsNoAlert(): void
    {
        $tripStateManager = $this->tripStateManager([$this->createStage('trip-1', 1, 25.0)]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WATER_POINT_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $this->waterPointRepository([]), $distributor, $haversine);
        $handler(new CheckWaterPoints('trip-1'));
    }

    #[Test]
    public function noStagesReturnsEarly(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $this->waterPointRepository([]), $distributor, $haversine);
        $handler(new CheckWaterPoints('trip-1'));
    }

    #[Test]
    public function longStageWithoutNearbyWaterPointEmitsNudgeWithNavigateAction(): void
    {
        $tripStateManager = $this->tripStateManager([$this->createStage('trip-1', 1, 50.0)]);

        // One water point globally, but the distributor assigns none to the stage → water gap.
        $waterPointRepository = $this->waterPointRepository([['lat' => 48.25, 'lon' => 2.25]]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(5000.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WATER_POINT_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 1 === \count($alerts)
                        && 'nudge' === $alerts[0]['type']
                        && 0 === $alerts[0]['stageIndex']
                        && 1 === $alerts[0]['dayNumber']
                        && null !== $alerts[0]['action']
                        && AlertActionKind::NAVIGATE->value === $alerts[0]['action']['kind']
                        && 48.25 === $alerts[0]['action']['payload']['lat']
                        && 2.25 === $alerts[0]['action']['payload']['lon'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $waterPointRepository, $distributor, $haversine);
        $handler(new CheckWaterPoints('trip-1'));
    }

    #[Test]
    public function waterPointsIncludeDistanceFromStart(): void
    {
        $tripStateManager = $this->tripStateManager([$this->createStage('trip-1', 1)]);

        $waterPointRepository = $this->waterPointRepository([['lat' => 48.3, 'lon' => 2.3]]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([
            0 => [['lat' => 48.3, 'lon' => 2.3]],
        ]);

        // Each segment is 10km → cumulative: 0, 10, 20, 30, 40, 50
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(10.0);
        $haversine->method('inMeters')->willReturnCallback(
            static fn (float $lat1, float $lon1, float $lat2, float $lon2): float => 48.3 === $lat1 && 2.3 === $lon1 ? 0.0 : 10000.0,
        );

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WATER_POINT_ALERTS,
                $this->callback(static function (array $data): bool {
                    $wps = $data['waterPointsByStage'][0]['waterPoints'] ?? [];

                    return 1 === \count($wps)
                        && 48.3 === $wps[0]['lat']
                        && 2.3 === $wps[0]['lon']
                        && 30.0 === $wps[0]['distanceFromStart'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $waterPointRepository, $distributor, $haversine);
        $handler(new CheckWaterPoints('trip-1'));
    }
}
