<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckRailwayStations;
use App\MessageHandler\CheckRailwayStationsHandler;
use App\Osm\RailwayStationRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\TripCompletionGate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CheckRailwayStationsHandlerTest extends TestCase
{
    /**
     * @return list<Stage>
     */
    private function createStages(string $tripId, int $count = 3): array
    {
        $stages = [];
        for ($i = 1; $i <= $count; ++$i) {
            $stages[] = new Stage(
                tripId: $tripId,
                dayNumber: $i,
                distance: 80.0,
                elevation: 500.0,
                startPoint: new Coordinate(48.0 + ($i - 1) * 0.5, 2.0 + ($i - 1) * 0.5),
                endPoint: new Coordinate(48.0 + $i * 0.5, 2.0 + $i * 0.5),
            );
        }

        return $stages;
    }

    /**
     * @param list<array{name: ?string, category: string, lat: float, lon: float}> $stations
     */
    private function railwayStationRepository(array $stations): RailwayStationRepositoryInterface
    {
        $repository = $this->createStub(RailwayStationRepositoryInterface::class);
        $repository->method('findInCorridor')->willReturnCallback(
            static function (array $route, int $radiusMeters) use ($stations): array {
                self::assertSame(10000, $radiusMeters, 'findInCorridor must use the 10 km station radius');

                return $stations;
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

        return $tripStateManager;
    }

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        RailwayStationRepositoryInterface $railwayStationRepository,
        GeoDistanceInterface $haversine,
    ): CheckRailwayStationsHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => match ($id) {
                'alert.railway_station.nudge' => \sprintf('No train station near stage %s.', $params['%stage%']),
                default => $id,
            },
        );

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        $messageBus = $this->createStub(MessageBusInterface::class);

        $handler = new CheckRailwayStationsHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $railwayStationRepository,
            $haversine,
            $translator,
            $messageBus,
        );
        $handler->setCompletionGate(new TripCompletionGate($computationTracker, $publisher, $messageBus));

        return $handler;
    }

    #[Test]
    public function stationNearbyEmitsNoAlert(): void
    {
        $tripStateManager = $this->tripStateManager($this->createStages('trip-1'));
        $railwayStationRepository = $this->railwayStationRepository([
            ['name' => 'Gare de Lyon', 'category' => 'station', 'lat' => 48.5, 'lon' => 2.5],
        ]);

        // Station is within 10 km of every stage endpoint
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(5000.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::RAILWAY_STATION_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $railwayStationRepository, $haversine);
        $handler(new CheckRailwayStations('trip-1'));
    }

    #[Test]
    public function noStationNearbyEmitsNudgeForEveryStage(): void
    {
        $tripStateManager = $this->tripStateManager($this->createStages('trip-1'));
        $railwayStationRepository = $this->railwayStationRepository([]);

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::RAILWAY_STATION_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 3 === \count($alerts)
                        && 'nudge' === $alerts[0]['type']
                        && str_contains((string) $alerts[0]['message'], 'No train station near stage 1')
                        && !isset($alerts[0]['action']);
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $railwayStationRepository, $haversine);
        $handler(new CheckRailwayStations('trip-1'));
    }

    #[Test]
    public function stationFarFromOneStageEmitsNudgeWithNavigateAction(): void
    {
        // Stage 1: start(48.0,2.0) end(48.5,2.5) — station at start, within range
        // Stage 2: start(48.5,2.5) end(49.0,3.0) — both endpoints far from station
        $tripStateManager = $this->tripStateManager($this->createStages('trip-1', 2));
        $railwayStationRepository = $this->railwayStationRepository([
            ['name' => 'Gare de Lyon', 'category' => 'station', 'lat' => 48.0, 'lon' => 2.0],
        ]);

        // lat1 is the stage endpoint lat; lat1 < 48.5 → Stage 1 endpoints (near), lat1 >= 48.5 → Stage 2 endpoints (far)
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturnCallback(
            static fn (float $lat1): float => $lat1 >= 48.5 ? 15000.0 : 5000.0,
        );

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::RAILWAY_STATION_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    // Only Stage 2 has no nearby station and gets a nudge alert
                    return 1 === \count($alerts)
                        && 'nudge' === $alerts[0]['type']
                        && 'navigate' === $alerts[0]['action']
                        && isset($alerts[0]['actionLat'])
                        && isset($alerts[0]['actionLon']);
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $railwayStationRepository, $haversine);
        $handler(new CheckRailwayStations('trip-1'));
    }

    #[Test]
    public function restDayStageIsSkippedAndEmitsNoAlert(): void
    {
        $stages = $this->createStages('trip-1', 2);
        // Override stage 1 as a rest day — stage 2 remains a cycling stage
        $stages[0] = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 0.0,
            elevation: 0.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.0, 2.0),
            isRestDay: true,
        );

        $tripStateManager = $this->tripStateManager($stages);

        // No stations found — without the rest-day guard, both stages would produce alerts
        $railwayStationRepository = $this->railwayStationRepository([]);

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::RAILWAY_STATION_ALERTS,
                // Only stage 2 gets an alert — stage 1 is a rest day and must be skipped
                $this->callback(static fn (array $data): bool => 1 === \count($data['alerts'])),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $railwayStationRepository, $haversine);
        $handler(new CheckRailwayStations('trip-1'));
    }

    #[Test]
    public function nullStagesReturnsEarly(): void
    {
        $tripStateManager = $this->tripStateManager(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $handler = $this->createHandler(
            $tripStateManager,
            $publisher,
            $this->railwayStationRepository([]),
            $this->createStub(GeoDistanceInterface::class),
        );
        $handler(new CheckRailwayStations('trip-1'));
    }
}
