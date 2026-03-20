<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\RecalculateRouteSegment;
use App\MessageHandler\RecalculateRouteSegmentHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Routing\RoutingProviderInterface;
use App\Routing\RoutingResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RecalculateRouteSegmentHandlerTest extends TestCase
{
    #[Test]
    public function invokePublishesRoutingResult(): void
    {
        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 50000.0,
            elevation: 500.0,
            startPoint: new Coordinate(50.0, 2.0),
            endPoint: new Coordinate(50.1, 2.1),
        );

        $routingResult = new RoutingResult(
            coordinates: [new Coordinate(50.0, 2.0), new Coordinate(50.05, 2.05), new Coordinate(50.1, 2.1)],
            distance: 52000.0,
            elevationGain: 520.0,
            duration: 7200.0,
        );

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);

        $routingProvider = $this->createMock(RoutingProviderInterface::class);
        $routingProvider->expects($this->once())
            ->method('calculateRoute')
            ->willReturn($routingResult);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::ROUTE_SEGMENT_RECALCULATED,
                $this->callback(static fn (array $data): bool => 0 === $data['stageIndex']
                    && 'poi_detour' === $data['reason']
                    && 52000.0 === $data['distance']
                    && 520.0 === $data['elevationGain']
                    && 7200.0 === $data['duration']
                    && 3 === \count($data['coordinates'])),
            );

        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        $handler = new RecalculateRouteSegmentHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $routingProvider,
        );

        $handler(new RecalculateRouteSegment(
            tripId: 'trip-1',
            stageIndex: 0,
            waypointLat: 50.05,
            waypointLon: 2.05,
            reason: 'poi_detour',
        ));
    }

    #[Test]
    public function invokeWithNullStagesReturnsEarly(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn(null);

        $routingProvider = $this->createMock(RoutingProviderInterface::class);
        $routingProvider->expects($this->never())->method('calculateRoute');

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        $handler = new RecalculateRouteSegmentHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $routingProvider,
        );

        $handler(new RecalculateRouteSegment(
            tripId: 'trip-1',
            stageIndex: 0,
            waypointLat: 50.0,
            waypointLon: 2.0,
            reason: 'poi_detour',
        ));
    }

    #[Test]
    public function invokeWithInvalidStageIndexReturnsEarly(): void
    {
        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 50000.0,
            elevation: 500.0,
            startPoint: new Coordinate(50.0, 2.0),
            endPoint: new Coordinate(50.1, 2.1),
        );

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);

        $routingProvider = $this->createMock(RoutingProviderInterface::class);
        $routingProvider->expects($this->never())->method('calculateRoute');

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);

        $handler = new RecalculateRouteSegmentHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $routingProvider,
        );

        $handler(new RecalculateRouteSegment(
            tripId: 'trip-1',
            stageIndex: 5,
            waypointLat: 50.0,
            waypointLon: 2.0,
            reason: 'accommodation_reroute',
        ));
    }
}
