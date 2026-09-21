<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Engine\DistanceCalculatorInterface;
use App\Enum\ComputationTrigger;
use App\Message\RecalculateStages;
use App\ApiResource\TripRequest;
use App\Repository\TripRequestRepositoryInterface;
use App\State\StageDeleteProcessor;
use App\State\StageLocator;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class StageDeleteProcessorTest extends TestCase
{
    use MutateStagesStubTrait;

    private MockObject&TripRequestRepositoryInterface $tripStateManager;

    private MockObject&MessageBusInterface $messageBus;

    /**
     * @var Stub&DistanceCalculatorInterface
     */
    private Stub $distanceCalculator;

    private StageDeleteProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        $this->tripStateManager = $this->createMock(TripRequestRepositoryInterface::class);
        $this->stubMutateStages($this->tripStateManager);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);


        // Return a non-locked request by default (startDate in the future)
        $unlockedRequest = new TripRequest();
        $unlockedRequest->startDate = new \DateTimeImmutable('+30 days');
        $this->tripStateManager->method('getRequest')->willReturn($unlockedRequest);

        $this->processor = new StageDeleteProcessor(
            $this->tripStateManager,
            $this->messageBus,
            $this->distanceCalculator,
            new TripLocker(),
            new StageLocator(),
        );
    }

    #[Test]
    public function deletingRestDaySplicesItOutAndReindexesDayNumbers(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);

        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);
        $restDay = new Stage(tripId: 'trip-1', dayNumber: 2, distance: 0.0, elevation: 0.0, startPoint: $coord, endPoint: $coord, isRestDay: true);
        $stage2 = new Stage(tripId: 'trip-1', dayNumber: 3, distance: 90.0, elevation: 600.0, startPoint: $coord, endPoint: $coord);

        $capturedStages = null;
        $this->tripStateManager->method('getStages')->willReturn([$stage0, $restDay, $stage2]);
        $this->tripStateManager->method('getSourceType')->willReturn(null);
        $this->tripStateManager->expects($this->once())
            ->method('storeStages')
            ->with('trip-1', $this->callback(static function (array $stages) use (&$capturedStages): bool {
                $capturedStages = $stages;

                return true;
            }));
        $this->messageBus->method('dispatch')->willReturnCallback(static fn (object $msg): Envelope => new Envelope($msg));

        $this->processor->process(null, new Delete(), ['tripId' => 'trip-1', 'stageId' => $restDay->id]);

        $this->assertNotNull($capturedStages);
        $this->assertCount(2, $capturedStages);
        $this->assertSame(1, $capturedStages[0]->dayNumber);
        $this->assertSame(2, $capturedStages[1]->dayNumber);
        $this->assertFalse($capturedStages[0]->isRestDay);
        $this->assertFalse($capturedStages[1]->isRestDay);
    }

    #[Test]
    public function deletingRestDayDispatchesRecalculateStagesWithEmptyIndices(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);

        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);
        $restDay = new Stage(tripId: 'trip-1', dayNumber: 2, distance: 0.0, elevation: 0.0, startPoint: $coord, endPoint: $coord, isRestDay: true);
        $stage2 = new Stage(tripId: 'trip-1', dayNumber: 3, distance: 90.0, elevation: 600.0, startPoint: $coord, endPoint: $coord);

        $this->tripStateManager->method('getStages')->willReturn([$stage0, $restDay, $stage2]);
        $this->tripStateManager->method('getSourceType')->willReturn(null);

        $dispatchedMessages = [];
        $this->messageBus->method('dispatch')->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
            $dispatchedMessages[] = $msg;

            return new Envelope($msg);
        });

        $this->processor->process(null, new Delete(), ['tripId' => 'trip-1', 'stageId' => $restDay->id]);

        $recalculate = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof RecalculateStages));
        $this->assertCount(1, $recalculate);
        $this->assertSame('trip-1', $recalculate[0]->tripId);
        $this->assertSame([], $recalculate[0]->affectedStageIds);
        // Geographic scans must be skipped: deleting a rest day does not change geography
        // Deleting a rest day moves no geometry, only the later dates.
        $this->assertSame([ComputationTrigger::DATES], $recalculate[0]->triggers);
    }

    #[Test]
    public function deletingRegularStageDoesNotSkipGeographicScans(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);

        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);
        $stage1 = new Stage(tripId: 'trip-1', dayNumber: 2, distance: 90.0, elevation: 600.0, startPoint: $coord, endPoint: $coord);
        $stage2 = new Stage(tripId: 'trip-1', dayNumber: 3, distance: 70.0, elevation: 400.0, startPoint: $coord, endPoint: $coord);

        $this->tripStateManager->method('getStages')->willReturn([$stage0, $stage1, $stage2]);
        $this->tripStateManager->method('getSourceType')->willReturn(null);

        $dispatchedMessages = [];
        $this->messageBus->method('dispatch')->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
            $dispatchedMessages[] = $msg;

            return new Envelope($msg);
        });

        $this->processor->process(null, new Delete(), ['tripId' => 'trip-1', 'stageId' => $stage1->id]);

        $recalculate = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof RecalculateStages));
        $this->assertCount(1, $recalculate);
        // Geographic scans must NOT be skipped: deleting a regular stage changes geography
        // Deleting a ridden stage merges two lines and shifts every later date.
        $this->assertSame([ComputationTrigger::GEOMETRY, ComputationTrigger::DATES], $recalculate[0]->triggers);
    }

    /**
     * A merge shifts every later stage one day earlier, and the accommodation scan is the one
     * computation scoped to the affected list. Naming only the stage that absorbed the
     * geometry left every stage past the merge point with a seasonal verdict computed for the
     * old month — the defect ADR-070 exists to close, in the shape it takes here.
     */
    #[Test]
    public function aMergeNamesEveryStageWhoseDateShifts(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);
        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);
        $stage1 = new Stage(tripId: 'trip-1', dayNumber: 2, distance: 70.0, elevation: 400.0, startPoint: $coord, endPoint: $coord);
        $stage2 = new Stage(tripId: 'trip-1', dayNumber: 3, distance: 90.0, elevation: 600.0, startPoint: $coord, endPoint: $coord);
        $stage3 = new Stage(tripId: 'trip-1', dayNumber: 4, distance: 60.0, elevation: 300.0, startPoint: $coord, endPoint: $coord);

        $this->tripStateManager->method('getStages')->willReturn([$stage0, $stage1, $stage2, $stage3]);
        $this->tripStateManager->method('getSourceType')->willReturn(null);

        $dispatchedMessages = [];
        $this->messageBus->method('dispatch')->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
            $dispatchedMessages[] = $msg;

            return new Envelope($msg);
        });

        $this->processor->process(null, new Delete(), ['tripId' => 'trip-1', 'stageId' => $stage1->id]);

        $recalculate = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof RecalculateStages));
        $this->assertCount(1, $recalculate);

        // Both halves: the line moved where the merge happened, the dates moved everywhere after.
        $this->assertSame([ComputationTrigger::GEOMETRY, ComputationTrigger::DATES], $recalculate[0]->triggers);

        // The stages left standing after the merge, not just the one that absorbed it.
        $this->assertGreaterThan(1, \count($recalculate[0]->affectedStageIds));
        $this->assertContains($stage3->id, $recalculate[0]->affectedStageIds);
    }

    #[Test]
    public function deletingARestDayInvalidatesTheDatesAndNotTheLine(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);
        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);
        $restDay = new Stage(tripId: 'trip-1', dayNumber: 2, distance: 0.0, elevation: 0.0, startPoint: $coord, endPoint: $coord, isRestDay: true);

        $stage2 = new Stage(tripId: 'trip-1', dayNumber: 3, distance: 90.0, elevation: 600.0, startPoint: $coord, endPoint: $coord);

        $this->tripStateManager->method('getStages')->willReturn([$stage0, $restDay, $stage2]);
        $this->tripStateManager->method('getSourceType')->willReturn(null);

        $dispatchedMessages = [];
        // One message, carrying what the edit invalidated (ADR-070).
        $this->messageBus->expects($this->exactly(1))
            ->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $msg;

                return new Envelope($msg);
            });

        $this->processor->process(null, new Delete(), ['tripId' => 'trip-1', 'stageId' => $restDay->id]);

        $recalculate = $dispatchedMessages[0];
        $this->assertInstanceOf(RecalculateStages::class, $recalculate);
        $this->assertSame([ComputationTrigger::DATES], $recalculate->triggers);
    }

    #[Test]
    public function lockedTripThrowsHttpException(): void
    {
        $lockedRequest = new TripRequest();
        $lockedRequest->startDate = new \DateTimeImmutable('yesterday');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);

        $this->stubMutateStages($tripStateManager);
        $tripStateManager->method('getRequest')->willReturn($lockedRequest);
        $tripStateManager->method('getStages')->willReturn([]);


        $processor = new StageDeleteProcessor(
            $tripStateManager,
            $this->createStub(MessageBusInterface::class),
            $this->createStub(DistanceCalculatorInterface::class),
            new TripLocker(),
            new StageLocator(),
        );

        try {
            $processor->process(null, new Delete(), ['tripId' => 'trip-1', 'stageId' => Uuid::v7()->toRfc4122()]);
            self::fail('Expected HttpException to be thrown.');
        } catch (HttpException $httpException) {
            self::assertSame(423, $httpException->getStatusCode());
        }
    }
}
