<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\PacingEngineInterface;
use App\Engine\RouteSimplifierInterface;
use App\Enum\SourceType;
use App\Enum\TripStatus;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\GenerateStages;
use App\MessageHandler\GenerateStagesHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\StructuralComputationService;
use App\Service\TripAnalysisDispatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class GenerateStagesHandlerTest extends TestCase
{
    private function structuralComputation(
        TripRequestRepositoryInterface $tripStateManager,
        PacingEngineInterface $pacingEngine,
        ?DistanceCalculatorInterface $distanceCalculator = null,
    ): StructuralComputationService {
        return new StructuralComputationService(
            $tripStateManager,
            $distanceCalculator ?? $this->createStub(DistanceCalculatorInterface::class),
            $this->createStub(ElevationCalculatorInterface::class),
            $this->createStub(RouteSimplifierInterface::class),
            $pacingEngine,
        );
    }

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        StructuralComputationService $structuralComputation,
        MessageBusInterface $messageBus,
    ): GenerateStagesHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        return new GenerateStagesHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $structuralComputation,
            new TripAnalysisDispatcher($messageBus),
            $messageBus,
        );
    }

    #[Test]
    public function stagesComputedPayloadIncludesGeometry(): void
    {
        $coordinate = new Coordinate(48.8566, 2.3522, 35.0);

        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: $coordinate,
            endPoint: new Coordinate(49.0, 2.5, 50.0),
            geometry: [$coordinate],
        );

        $tripRequest = new TripRequest();

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($tripRequest);
        $tripStateManager->method('getSourceType')->willReturn(SourceType::KOMOOT_TOUR->value);
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
            ['lat' => 49.0, 'lon' => 2.5, 'ele' => 50.0],
        ]);
        $tripStateManager->method('getRawPoints')->willReturn(null);

        $pacingEngine = $this->createStub(PacingEngineInterface::class);
        $pacingEngine->method('generateStages')->willReturn([$stage, $stage]);

        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        $distanceCalculator->method('calculateTotalDistance')->willReturn(80.0);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::STAGES_COMPUTED,
                $this->callback(static function (array $data): bool {
                    $geometry = $data['stages'][0]['geometry'] ?? null;

                    return \is_array($geometry)
                        && 1 === \count($geometry)
                        && \is_array($geometry[0])
                        && 48.8566 === $geometry[0]['lat']
                        && 2.3522 === $geometry[0]['lon']
                        && 35.0 === $geometry[0]['ele'];
                }),
            );

        $handler = $this->createHandler(
            $tripStateManager,
            $publisher,
            $this->structuralComputation($tripStateManager, $pacingEngine, $distanceCalculator),
            $messageBus,
        );

        $handler(new GenerateStages('trip-1'));
    }

    #[Test]
    public function numberOfDaysUsesMaxDistancePerDayFromProfile(): void
    {
        $coordinate = new Coordinate(48.8566, 2.3522, 35.0);

        $tripRequest = new TripRequest();
        $tripRequest->maxDistancePerDay = 45.0; // beginner profile

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($tripRequest);
        $tripStateManager->method('getSourceType')->willReturn(SourceType::KOMOOT_TOUR->value);
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
            ['lat' => 49.0, 'lon' => 2.5, 'ele' => 50.0],
        ]);
        $tripStateManager->method('getRawPoints')->willReturn(null);

        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        // 142km total → ceil(142/45) = 4 days (not ceil(142/80) = 2)
        $distanceCalculator->method('calculateTotalDistance')->willReturn(142.0);

        $pacingEngine = $this->createMock(PacingEngineInterface::class);
        $pacingEngine->expects($this->once())
            ->method('generateStages')
            ->with(
                'trip-1',
                $this->anything(),
                4, // numberOfDays: ceil(142/45)
                142.0,
                $this->anything(),
                $this->anything(),
                $this->anything(),
                45.0,
            )
            ->willReturn([
                new Stage(tripId: 'trip-1', dayNumber: 1, distance: 40.0, elevation: 100.0, startPoint: $coordinate, endPoint: $coordinate),
                new Stage(tripId: 'trip-1', dayNumber: 2, distance: 40.0, elevation: 100.0, startPoint: $coordinate, endPoint: $coordinate),
                new Stage(tripId: 'trip-1', dayNumber: 3, distance: 40.0, elevation: 100.0, startPoint: $coordinate, endPoint: $coordinate),
                new Stage(tripId: 'trip-1', dayNumber: 4, distance: 22.0, elevation: 50.0, startPoint: $coordinate, endPoint: $coordinate),
            ]);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        $handler = $this->createHandler(
            $tripStateManager,
            $publisher,
            $this->structuralComputation($tripStateManager, $pacingEngine, $distanceCalculator),
            $messageBus,
        );

        $handler(new GenerateStages('trip-1'));
    }

    #[Test]
    public function postsReadyStatusAfterStoringStages(): void
    {
        $coordinate = new Coordinate(48.8566, 2.3522, 35.0);

        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: $coordinate,
            endPoint: $coordinate,
            geometry: [$coordinate],
        );

        $tripRequest = new TripRequest();

        $tripStateManager = $this->createMock(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($tripRequest);
        $tripStateManager->method('getSourceType')->willReturn(SourceType::KOMOOT_TOUR->value);
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
            ['lat' => 49.0, 'lon' => 2.5, 'ele' => 50.0],
        ]);
        $tripStateManager->method('getRawPoints')->willReturn(null);

        // Status must be posted to `ready` once at least MIN_STAGES stages are stored.
        $tripStateManager->expects($this->once())
            ->method('storeStatus')
            ->with('trip-1', TripStatus::READY->value);

        $pacingEngine = $this->createStub(PacingEngineInterface::class);
        $pacingEngine->method('generateStages')->willReturn([$stage, $stage]);

        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        $distanceCalculator->method('calculateTotalDistance')->willReturn(80.0);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        $handler = $this->createHandler(
            $tripStateManager,
            $publisher,
            $this->structuralComputation($tripStateManager, $pacingEngine, $distanceCalculator),
            $messageBus,
        );

        $handler(new GenerateStages('trip-1'));
    }

    #[Test]
    public function doesNotPostReadyStatusWhenBelowMinStages(): void
    {
        $coordinate = new Coordinate(48.8566, 2.3522, 35.0);

        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: $coordinate,
            endPoint: $coordinate,
            geometry: [$coordinate],
        );

        $tripRequest = new TripRequest();

        $tripStateManager = $this->createMock(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($tripRequest);
        $tripStateManager->method('getSourceType')->willReturn(SourceType::KOMOOT_TOUR->value);
        $tripStateManager->method('getDecimatedPoints')->willReturn([
            ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
        ]);
        $tripStateManager->method('getRawPoints')->willReturn(null);

        $tripStateManager->expects($this->never())->method('storeStatus');

        $pacingEngine = $this->createStub(PacingEngineInterface::class);
        $pacingEngine->method('generateStages')->willReturn([$stage]); // single stage → below MIN_STAGES

        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        $distanceCalculator->method('calculateTotalDistance')->willReturn(20.0);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publishValidationError')
            ->with('trip-1', 'MIN_STAGES', $this->anything());

        $handler = $this->createHandler(
            $tripStateManager,
            $publisher,
            $this->structuralComputation($tripStateManager, $pacingEngine, $distanceCalculator),
            $messageBus,
        );

        $handler(new GenerateStages('trip-1'));
    }

    #[Test]
    public function noRequestReturnsEarly(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $messageBus = $this->createStub(MessageBusInterface::class);

        $handler = $this->createHandler(
            $tripStateManager,
            $publisher,
            $this->structuralComputation($tripStateManager, $this->createStub(PacingEngineInterface::class)),
            $messageBus,
        );

        $handler(new GenerateStages('trip-1'));
    }
}
