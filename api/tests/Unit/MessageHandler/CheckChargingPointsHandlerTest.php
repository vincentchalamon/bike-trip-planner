<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckChargingPoints;
use App\MessageHandler\CheckChargingPointsHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CheckChargingPointsHandlerTest extends TestCase
{
    private function createStage(string $tripId, int $dayNumber, float $endLat = 48.1, float $endLon = 2.1): Stage
    {
        return new Stage(
            tripId: $tripId,
            dayNumber: $dayNumber,
            distance: 80000.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate($endLat, $endLon),
        );
    }

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        ScannerInterface $scanner,
        QueryBuilderInterface $queryBuilder,
        GeoDistanceInterface $haversine,
    ): CheckChargingPointsHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => \sprintf('No charging point near stage %s.', $params['%stage%']),
        );

        return new CheckChargingPointsHandler(
            $computationTracker,
            $publisher,
            $tripStateManager,
            $scanner,
            $queryBuilder,
            $haversine,
            $translator,
        );
    }

    #[Test]
    public function stageWithNearbyChargingPointEmitsNoAlert(): void
    {
        $stages = [$this->createStage('trip-1', 1)];

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.0, 'lon' => 2.0, 'ele' => 0.0],
            ['lat' => 48.1, 'lon' => 2.1, 'ele' => 0.0],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildChargingPointQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.1, 'lon' => 2.1],
            ],
        ]);

        $haversine = $this->createStub(GeoDistanceInterface::class);
        // Charging point is very close to endpoint
        $haversine->method('inMeters')->willReturn(100.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::CHARGING_POINT_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckChargingPoints('trip-1'));
    }

    #[Test]
    public function stageWithoutChargingPointEmitsNudge(): void
    {
        $stages = [$this->createStage('trip-1', 1)];

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.0, 'lon' => 2.0, 'ele' => 0.0],
            ['lat' => 48.1, 'lon' => 2.1, 'ele' => 0.0],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildChargingPointQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn(['elements' => []]);

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::CHARGING_POINT_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 1 === \count($alerts)
                        && 'nudge' === $alerts[0]['type']
                        && 0 === $alerts[0]['stageIndex']
                        && 1 === $alerts[0]['dayNumber'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckChargingPoints('trip-1'));
    }

    #[Test]
    public function noStagesReturnsEarly(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $scanner = $this->createStub(ScannerInterface::class);
        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckChargingPoints('trip-1'));
    }
}
