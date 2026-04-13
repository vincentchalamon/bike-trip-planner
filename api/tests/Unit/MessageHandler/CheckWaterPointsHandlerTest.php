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
use App\Message\CheckWaterPoints;
use App\MessageHandler\CheckWaterPointsHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
        ScannerInterface $scanner,
        QueryBuilderInterface $queryBuilder,
        GeometryDistributorInterface $distributor,
        GeoDistanceInterface $haversine,
    ): CheckWaterPointsHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => \sprintf('No cemetery on stage %s for 30+ km.', $params['%stage%']),
        );

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        return new CheckWaterPointsHandler(
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

    #[Test]
    public function stageWithWaterPointsEmitsNoAlert(): void
    {
        // Stage of 50km with 3 water points evenly spread → no 30km gap
        $stages = [$this->createStage('trip-1', 1, 50.0)];

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.0, 'lon' => 2.0, 'ele' => 0.0],
            ['lat' => 48.5, 'lon' => 2.5, 'ele' => 0.0],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildCemeteryQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.1, 'lon' => 2.1],
                ['lat' => 48.3, 'lon' => 2.3],
                ['lat' => 48.4, 'lon' => 2.4],
            ],
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
        // Water points at indices 1 (10km), 3 (30km), 4 (40km)
        // Gaps: 0→10=10, 10→30=20, 30→40=10, 40→50=10 → all < 30km
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(10.0);
        $haversine->method('inMeters')->willReturnCallback(

            // Return 0 for exact match, large value otherwise
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

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine);
        $handler(new CheckWaterPoints('trip-1'));
    }

    #[Test]
    public function longStageWithoutWaterPointEmitsNudge(): void
    {
        $stages = [$this->createStage('trip-1', 1, 50.0)];

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.0, 'lon' => 2.0, 'ele' => 0.0],
            ['lat' => 48.5, 'lon' => 2.5, 'ele' => 0.0],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildCemeteryQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn(['elements' => []]);

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

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine);
        $handler(new CheckWaterPoints('trip-1'));
    }

    #[Test]
    public function shortStageWithoutWaterPointEmitsNoAlert(): void
    {
        $stages = [$this->createStage('trip-1', 1, 25.0)];

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.0, 'lon' => 2.0, 'ele' => 0.0],
            ['lat' => 48.5, 'lon' => 2.5, 'ele' => 0.0],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildCemeteryQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn(['elements' => []]);

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

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine);
        $handler(new CheckWaterPoints('trip-1'));
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
        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $haversine = $this->createStub(GeoDistanceInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine);
        $handler(new CheckWaterPoints('trip-1'));
    }

    #[Test]
    public function waterPointsIncludeDistanceFromStart(): void
    {
        $stages = [$this->createStage('trip-1', 1)];

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.0, 'lon' => 2.0, 'ele' => 0.0],
            ['lat' => 48.5, 'lon' => 2.5, 'ele' => 0.0],
        ]);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildCemeteryQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                ['lat' => 48.3, 'lon' => 2.3],
            ],
        ]);

        $distributor = $this->createStub(GeometryDistributorInterface::class);
        $distributor->method('distributeByGeometry')->willReturn([
            0 => [['lat' => 48.3, 'lon' => 2.3]],
        ]);

        // Each segment is 10km → cumulative: 0, 10, 20, 30, 40, 50
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inKilometers')->willReturn(10.0);
        // Water point is closest to index 3 (48.3, 2.3)
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

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $distributor, $haversine);
        $handler(new CheckWaterPoints('trip-1'));
    }
}
