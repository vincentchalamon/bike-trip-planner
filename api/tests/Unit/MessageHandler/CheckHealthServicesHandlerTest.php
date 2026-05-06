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
use App\Message\CheckHealthServices;
use App\MessageHandler\CheckHealthServicesHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CheckHealthServicesHandlerTest extends TestCase
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
                startPoint: new Coordinate(48.0, 2.0),
                endPoint: new Coordinate(48.5, 2.5),
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
    ): CheckHealthServicesHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);
        $computationTracker->method('areAllEnrichmentsCompleted')->willReturn(false);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => match ($id) {
                'alert.health_service.nudge' => \sprintf('No health service near stage %s.', $params['%stage%']),
                default => $id,
            },
        );

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        return new CheckHealthServicesHandler(
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
    public function nearbyPharmacyEmitsNoAlert(): void
    {
        $stages = $this->createStages('trip-1');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildHealthServiceQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'lat' => 48.25,
                    'lon' => 2.25,
                    'tags' => ['amenity' => 'pharmacy'],
                ],
            ],
        ]);

        // Pharmacy is close to every stage's midpoint
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(5000.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::HEALTH_SERVICE_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckHealthServices('trip-1'));
    }

    #[Test]
    public function noHealthServiceEmitsNudgeForEveryStage(): void
    {
        $stages = $this->createStages('trip-1');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildHealthServiceQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn(['elements' => []]);

        $haversine = $this->createStub(GeoDistanceInterface::class);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::HEALTH_SERVICE_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 3 === \count($alerts)
                        && 'nudge' === $alerts[0]['type']
                        && str_contains((string) $alerts[0]['message'], 'No health service near stage 1');
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckHealthServices('trip-1'));
    }

    #[Test]
    public function distantHealthServiceEmitsNudge(): void
    {
        $stages = $this->createStages('trip-1', 2);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildHealthServiceQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'lat' => 49.0,
                    'lon' => 3.0,
                    'tags' => ['amenity' => 'hospital'],
                ],
            ],
        ]);

        // Hospital is too far from all stages (> 15 km)
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(20000.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::HEALTH_SERVICE_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 2 === \count($alerts)
                        && 'nudge' === $alerts[0]['type'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckHealthServices('trip-1'));
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
        $handler(new CheckHealthServices('trip-1'));
    }

    #[Test]
    public function clinicWithinRadiusEmitsNoAlert(): void
    {
        $stages = $this->createStages('trip-1', 1);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getLocale')->willReturn('en');
        $tripStateManager->method('getDecimatedPoints')->willReturn(null);

        $queryBuilder = $this->createStub(QueryBuilderInterface::class);
        $queryBuilder->method('buildHealthServiceQuery')->willReturn('query');

        $scanner = $this->createStub(ScannerInterface::class);
        $scanner->method('query')->willReturn([
            'elements' => [
                [
                    'center' => ['lat' => 48.3, 'lon' => 2.3],
                    'tags' => ['amenity' => 'clinic'],
                ],
            ],
        ]);

        // Clinic within radius
        $haversine = $this->createStub(GeoDistanceInterface::class);
        $haversine->method('inMeters')->willReturn(10000.0);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::HEALTH_SERVICE_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $scanner, $queryBuilder, $haversine);
        $handler(new CheckHealthServices('trip-1'));
    }
}
