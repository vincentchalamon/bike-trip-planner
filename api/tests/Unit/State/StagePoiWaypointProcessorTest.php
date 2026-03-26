<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use Override;
use DateTimeImmutable;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\StagePoiWaypointRequest;
use App\ApiResource\StageResponse;
use App\ApiResource\TripRequest;
use App\Message\RecalculateRouteSegment;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\State\StagePoiWaypointProcessor;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

#[AllowMockObjectsWithoutExpectations]
final class StagePoiWaypointProcessorTest extends TestCase
{
    private MockObject&TripRequestRepositoryInterface $tripStateManager;

    private MockObject&MessageBusInterface $messageBus;

    private MockObject&ObjectMapperInterface $objectMapper;

    private StagePoiWaypointProcessor $processor;

    #[Override]
    protected function setUp(): void
    {
        $this->tripStateManager = $this->createMock(TripRequestRepositoryInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->objectMapper = $this->createMock(ObjectMapperInterface::class);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('current')->willReturn(1);

        $unlockedRequest = new TripRequest();
        $unlockedRequest->startDate = new DateTimeImmutable('+30 days');
        $this->tripStateManager->method('getRequest')->willReturn($unlockedRequest);

        $this->processor = new StagePoiWaypointProcessor(
            $this->tripStateManager,
            $this->messageBus,
            $this->objectMapper,
            $generationTracker,
            new TripLocker(),
        );
    }

    private function createStage(int $dayNumber): Stage
    {
        $coord = new Coordinate(lat: 48.0, lon: 2.0);

        return new Stage(
            tripId: 'trip-1',
            dayNumber: $dayNumber,
            distance: 80.0,
            elevation: 500.0,
            startPoint: $coord,
            endPoint: $coord,
        );
    }

    #[Test]
    public function validRequestDispatchesRecalculateRouteSegment(): void
    {
        $stage = $this->createStage(1);
        $this->tripStateManager->method('getStages')->willReturn([$stage]);

        $dispatchedMessages = [];
        $this->messageBus->method('dispatch')->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
            $dispatchedMessages[] = $msg;

            return new Envelope($msg);
        });

        $this->objectMapper->method('map')->willReturn(new StageResponse());

        $data = new StagePoiWaypointRequest(waypointLat: 48.2, waypointLon: 2.3);
        $this->processor->process($data, new Post(), ['tripId' => 'trip-1', 'index' => 0]);

        $recalculate = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof RecalculateRouteSegment));
        self::assertCount(1, $recalculate);
        self::assertSame('trip-1', $recalculate[0]->tripId);
        self::assertSame(0, $recalculate[0]->stageIndex);
        self::assertSame(48.2, $recalculate[0]->waypointLat);
        self::assertSame(2.3, $recalculate[0]->waypointLon);
        self::assertSame('poi_detour', $recalculate[0]->reason);
    }

    #[Test]
    public function nonNumericIndexThrowsBadRequestHttpException(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $data = new StagePoiWaypointRequest(waypointLat: 48.2, waypointLon: 2.3);
        $this->processor->process($data, new Post(), ['tripId' => 'trip-1', 'index' => 'abc']);
    }

    #[Test]
    public function unknownStageIndexThrowsNotFoundHttpException(): void
    {
        $this->tripStateManager->method('getStages')->willReturn([]);
        $this->expectException(NotFoundHttpException::class);

        $data = new StagePoiWaypointRequest(waypointLat: 48.2, waypointLon: 2.3);
        $this->processor->process($data, new Post(), ['tripId' => 'trip-1', 'index' => 99]);
    }

    #[Test]
    public function lockedTripThrowsHttpException(): void
    {
        $coord = new Coordinate(lat: 48.0, lon: 2.0);
        $stage = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);

        $lockedRequest = new TripRequest();
        $lockedRequest->startDate = new DateTimeImmutable('yesterday');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);
        $tripStateManager->method('getRequest')->willReturn($lockedRequest);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('current')->willReturn(1);

        $processor = new StagePoiWaypointProcessor(
            $tripStateManager,
            $this->createStub(MessageBusInterface::class),
            $this->createStub(ObjectMapperInterface::class),
            $generationTracker,
            new TripLocker(),
        );

        try {
            $processor->process(new StagePoiWaypointRequest(waypointLat: 48.2, waypointLon: 2.3), new Post(), ['tripId' => 'trip-1', 'index' => 0]);
            self::fail('Expected HttpException to be thrown.');
        } catch (HttpException $httpException) {
            self::assertSame(423, $httpException->getStatusCode());
        }
    }
}
