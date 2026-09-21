<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\Tests\Unit\AlertMessageTestTrait;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Enum\ComputationTrigger;
use App\Message\RecalculateStages;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Mapper\StageResponseMapper;
use App\Repository\TripRequestRepositoryInterface;
use App\State\RestDayInsertProcessor;
use App\State\StageLocator;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class RestDayInsertProcessorTest extends TestCase
{
    use AlertMessageTestTrait;

    use MutateStagesStubTrait;

    private MockObject&TripRequestRepositoryInterface $tripStateManager;

    private MockObject&MessageBusInterface $messageBus;

    private StageResponseMapper $stageResponseMapper;

    private RestDayInsertProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        $this->tripStateManager = $this->createMock(TripRequestRepositoryInterface::class);
        $this->stubMutateStages($this->tripStateManager);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->stageResponseMapper = new StageResponseMapper(
            $this->createStub(ComputationTrackerInterface::class),
            $this->createStub(TripRequestRepositoryInterface::class),
            $this->createAlertRenderer(),
            $this->createReaderLocale(),
        );


        $this->processor = new RestDayInsertProcessor(
            $this->tripStateManager,
            $this->messageBus,
            $this->stageResponseMapper,
            new TripLocker(),
            new StageLocator(),
        );
    }

    #[Test]
    public function lockedTripThrowsHttpException(): void
    {
        $lockedRequest = new TripRequest();
        $lockedRequest->startDate = new \DateTimeImmutable('yesterday');

        $this->tripStateManager->method('getRequest')->willReturn($lockedRequest);
        $this->tripStateManager->method('getStages')->willReturn([]);

        try {
            $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'stageId' => Uuid::v7()->toRfc4122()]);
            self::fail('Expected HttpException to be thrown.');
        } catch (HttpException $httpException) {
            self::assertSame(423, $httpException->getStatusCode());
        }
    }

    #[Test]
    public function throwsNotFoundWhenIndexIsOutOfBounds(): void
    {
        $this->tripStateManager->method('getRequest')->willReturn(new TripRequest());
        $this->tripStateManager->method('getStages')->willReturn([]);

        $this->expectException(NotFoundHttpException::class);

        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'stageId' => Uuid::v7()->toRfc4122()]);
    }

    #[Test]
    public function throwsUnprocessableEntityWhenStageItselfIsRestDay(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);
        $restDay = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 0.0, elevation: 0.0, startPoint: $coord, endPoint: $coord, isRestDay: true);
        $stage1 = new Stage(tripId: 'trip-1', dayNumber: 2, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);

        $this->tripStateManager->method('getRequest')->willReturn(new TripRequest());
        $this->tripStateManager->method('getStages')->willReturn([$restDay, $stage1]);

        $this->expectException(UnprocessableEntityHttpException::class);

        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'stageId' => $restDay->id]);
    }

    #[Test]
    public function throwsUnprocessableEntityWhenNextStageIsRestDay(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);
        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);
        $restDay = new Stage(tripId: 'trip-1', dayNumber: 2, distance: 0.0, elevation: 0.0, startPoint: $coord, endPoint: $coord, isRestDay: true);
        $stage2 = new Stage(tripId: 'trip-1', dayNumber: 3, distance: 90.0, elevation: 600.0, startPoint: $coord, endPoint: $coord);

        $this->tripStateManager->method('getRequest')->willReturn(new TripRequest());
        $this->tripStateManager->method('getStages')->willReturn([$stage0, $restDay, $stage2]);

        $this->expectException(UnprocessableEntityHttpException::class);

        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'stageId' => $stage0->id]);
    }

    #[Test]
    public function insertsRestDayAtCorrectPositionAndRenumbersStages(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);
        $coord2 = new Coordinate(lat: 46.0, lon: 6.0);

        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord2);
        $stage1 = new Stage(tripId: 'trip-1', dayNumber: 2, distance: 90.0, elevation: 600.0, startPoint: $coord2, endPoint: $coord);

        $capturedStages = null;
        $this->tripStateManager->method('getStages')->willReturn([$stage0, $stage1]);
        $this->tripStateManager->expects($this->once())
            ->method('storeStages')
            ->with('trip-1', $this->callback(static function (array $stages) use (&$capturedStages): bool {
                $capturedStages = $stages;

                return true;
            }));
        $this->tripStateManager->method('getRequest')->willReturn(new TripRequest());
        $this->messageBus->method('dispatch')->willReturnCallback(static fn (object $msg): Envelope => new Envelope($msg));

        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'stageId' => $stage0->id]);

        $this->assertNotNull($capturedStages);
        $this->assertCount(3, $capturedStages);
        // Day numbers should be 1, 2, 3 after reindexing
        $this->assertSame(1, $capturedStages[0]->dayNumber);
        $this->assertSame(2, $capturedStages[1]->dayNumber);
        $this->assertSame(3, $capturedStages[2]->dayNumber);
        // Inserted rest day should be at index 1
        $this->assertTrue($capturedStages[1]->isRestDay);
        $this->assertSame(0.0, $capturedStages[1]->distance);
        // Rest day shares the endPoint of the stage before it
        $this->assertSame($stage0->endPoint, $capturedStages[1]->startPoint);
        $this->assertSame($stage0->endPoint, $capturedStages[1]->endPoint);
    }

    #[Test]
    public function returnsStageResponseMappedFromInsertedStage(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);
        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);

        $this->tripStateManager->method('getStages')->willReturn([$stage0]);
        $this->tripStateManager->method('getRequest')->willReturn(new TripRequest());
        $this->messageBus->method('dispatch')->willReturnCallback(static fn (object $msg): Envelope => new Envelope($msg));

        $result = $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'stageId' => $stage0->id]);

        // The response is built from the inserted rest day.
        $this->assertTrue($result->isRestDay);
        $this->assertSame('trip-1', $result->trip->id);
    }

    #[Test]
    public function dispatchesRecalculateStagesWithFullRange(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);
        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);
        $stage1 = new Stage(tripId: 'trip-1', dayNumber: 2, distance: 90.0, elevation: 600.0, startPoint: $coord, endPoint: $coord);
        $stage2 = new Stage(tripId: 'trip-1', dayNumber: 3, distance: 70.0, elevation: 400.0, startPoint: $coord, endPoint: $coord);

        $this->tripStateManager->method('getStages')->willReturn([$stage0, $stage1, $stage2]);
        $this->tripStateManager->method('getRequest')->willReturn(new TripRequest());

        $dispatchedMessages = [];
        $this->messageBus->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $msg;

                return new Envelope($msg);
            });

        // Insert after index 0 → rest day at position 1, affected indices = [1, 2, 3]
        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'stageId' => $stage0->id]);

        $recalculate = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof RecalculateStages));
        $this->assertCount(1, $recalculate);
        $this->assertSame('trip-1', $recalculate[0]->tripId);
        // Inserting after index 0 shifts the rest day and everything after it: the
        // identifiers are the inserted stage's plus the two that followed.
        $this->assertCount(3, $recalculate[0]->affectedStageIds);
        $this->assertSame([$stage1->id, $stage2->id], \array_slice($recalculate[0]->affectedStageIds, 1));
        // Geographic scans must be skipped: inserting a rest day does not change geography
        // A rest day moves no geometry: the dates alone are what it invalidated.
        $this->assertSame([ComputationTrigger::DATES], $recalculate[0]->triggers);
    }

    /**
     * The processor states what the edit invalidated and nothing more, whether or not the
     * trip has dates. Which of those computations actually go out is the dispatcher's call,
     * and its own guard covers it — asserting the absence of messages this processor never
     * builds would pass for the wrong reason (ADR-070).
     */
    #[Test]
    public function declaresTheDateShiftEvenOnATripWithNoStartDate(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);
        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);

        $tripRequest = new TripRequest();
        // startDate is null by default

        $this->tripStateManager->method('getStages')->willReturn([$stage0]);
        $this->tripStateManager->method('getRequest')->willReturn($tripRequest);

        $dispatchedMessages = [];
        $this->messageBus->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $msg;

                return new Envelope($msg);
            });

        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'stageId' => $stage0->id]);

        $recalculate = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof RecalculateStages));
        $this->assertCount(1, $recalculate);
        $this->assertSame([ComputationTrigger::DATES], $recalculate[0]->triggers);
    }

    #[Test]
    public function insertingARestDayInvalidatesTheDatesAndNotTheLine(): void
    {
        $coord = new Coordinate(48.0, 2.0);
        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);

        $tripRequest = new TripRequest();
        // Relative future date so the trip is never locked (startDate <= today),
        // otherwise this test becomes a time bomb once the hard-coded day passes.
        $tripRequest->startDate = new \DateTimeImmutable('+1 month');

        $this->tripStateManager->method('getStages')->willReturn([$stage0]);
        $this->tripStateManager->method('getRequest')->willReturn($tripRequest);

        $dispatchedMessages = [];
        // One message. What the edit invalidated travels on it, so the handler dispatches
        // that set once instead of the processor sending its own overlapping half (ADR-070).
        $this->messageBus->expects($this->exactly(1))
            ->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $msg;

                return new Envelope($msg);
            });

        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'stageId' => $stage0->id]);

        $recalculate = $dispatchedMessages[0];
        $this->assertInstanceOf(RecalculateStages::class, $recalculate);

        // A rest day adds no geometry; it only pushes every later stage onto a new date.
        // Terrain rides along in that set, which re-runs the rest-day nudge (recette).
        $this->assertSame([ComputationTrigger::DATES], $recalculate->triggers);
    }
}
