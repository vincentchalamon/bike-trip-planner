<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\Tests\Unit\AlertMessageTestTrait;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Message\AnalyzeTerrain;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
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
        $this->assertTrue($recalculate[0]->skipGeographicScans);
    }

    #[Test]
    public function doesNotDispatchWeatherAndCalendarWhenNoStartDate(): void
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

        $weatherMessages = array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof FetchWeather);
        $calendarMessages = array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof CheckCalendar);
        $this->assertCount(0, $weatherMessages);
        $this->assertCount(0, $calendarMessages);
    }

    #[Test]
    public function dispatchesFetchWeatherAndCheckCalendarWhenStartDateIsSet(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);
        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);

        $tripRequest = new TripRequest();
        // Relative future date so the trip is never locked (startDate <= today),
        // otherwise this test becomes a time bomb once the hard-coded day passes.
        $tripRequest->startDate = new \DateTimeImmutable('+1 month');

        $this->tripStateManager->method('getStages')->willReturn([$stage0]);
        $this->tripStateManager->method('getRequest')->willReturn($tripRequest);

        $dispatchedMessages = [];
        // RecalculateStages + AnalyzeTerrain (pacing/rest-day nudge re-run) +
        // FetchWeather + CheckCalendar = 4 dispatches when a start date is set.
        $this->messageBus->expects($this->exactly(4))
            ->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $msg;

                return new Envelope($msg);
            });

        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'stageId' => $stage0->id]);

        $weatherMessages = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof FetchWeather));
        $calendarMessages = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof CheckCalendar));
        $terrainMessages = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof AnalyzeTerrain));
        $this->assertCount(1, $weatherMessages);
        $this->assertSame('trip-1', $weatherMessages[0]->tripId);
        $this->assertCount(1, $calendarMessages);
        $this->assertSame('trip-1', $calendarMessages[0]->tripId);
        $this->assertCount(1, $terrainMessages);
    }
}
