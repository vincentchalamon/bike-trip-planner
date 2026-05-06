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
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
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

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        ScannerInterface $scanner,
        QueryBuilderInterface $queryBuilder,
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

        return new CheckRailwayStationsHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $scanner,
            $queryBuilder,
            $haversine,
            $translator,
            $this->createStub(MessageBusInterface::class),
        );
    }

    #[Test]
    public function stationNearbyEmitsNoAlert(): void
    {
        $stages = $this->createStages('trip-1');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildRailwayStationQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'lat' => 48.5,
                    'lon' => 2.5,
                    'tags' => ['name' => 'Gare de Lyon'],
                ],
            ],
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

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckRailwayStations('trip-1'));
    }

    #[Test]
    public function noStationNearbyEmitsNudgeForEveryStage(): void
    {
        $stages = $this->createStages('trip-1');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildRailwayStationQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn(['elements' => []]);

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

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckRailwayStations('trip-1'));
    }

    #[Test]
    public function stationFarFromOneStageEmitsNudgeWithNavigateAction(): void
    {
        // Stage 1: start(48.0,2.0) end(48.5,2.5) — station at start, within range
        // Stage 2: start(48.5,2.5) end(49.0,3.0) — both endpoints far from station
        $stages = $this->createStages('trip-1', 2);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildRailwayStationQuery')->willReturn('query');

        // Station at Stage 1's start point — near Stage 1, far from Stage 2
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'lat' => 48.0,
                    'lon' => 2.0,
                    'tags' => ['name' => 'Gare de Lyon'],
                ],
            ],
        ]);

        // lat1 is the stage endpoint lat; lat1 < 48.5 means Stage 1 endpoints (near), lat1 >= 48.5 means Stage 2 endpoints (far)
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

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
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

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildRailwayStationQuery')->willReturn('query');

        // No stations found — without the rest-day guard, both stages would produce alerts
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn(['elements' => []]);

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

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckRailwayStations('trip-1'));
    }

    #[Test]
    public function nullStagesReturnsEarly(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $scanner = $this->createStub(ScannerInterface::class);
        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckRailwayStations('trip-1'));
    }

    #[Test]
    public function stationWithCenterCoordinatesIsParsedCorrectly(): void
    {
        $stages = $this->createStages('trip-1', 1);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildRailwayStationQuery')->willReturn('query');

        // Station returned with center coordinates (way/relation)
        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'center' => ['lat' => 48.5, 'lon' => 2.5],
                    'tags' => ['name' => 'Gare du Nord'],
                ],
            ],
        ]);

        // Station within range
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(3000.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::RAILWAY_STATION_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckRailwayStations('trip-1'));
    }
}
