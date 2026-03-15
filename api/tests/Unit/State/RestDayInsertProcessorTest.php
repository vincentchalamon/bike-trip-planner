<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\StageResponse;
use App\ApiResource\TripRequest;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\State\RestDayInsertProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

final class RestDayInsertProcessorTest extends TestCase
{
    private MockObject&TripRequestRepositoryInterface $tripStateManager;

    private MockObject&MessageBusInterface $messageBus;

    private MockObject&ObjectMapperInterface $objectMapper;

    private RestDayInsertProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        $this->tripStateManager = $this->createMock(TripRequestRepositoryInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->objectMapper = $this->createMock(ObjectMapperInterface::class);

        $this->processor = new RestDayInsertProcessor(
            $this->tripStateManager,
            $this->messageBus,
            $this->objectMapper,
        );
    }

    #[Test]
    public function throwsNotFoundWhenIndexIsOutOfBounds(): void
    {
        $this->tripStateManager->method('getStages')->willReturn([]);

        $this->expectException(NotFoundHttpException::class);

        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'index' => 5]);
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
        $this->tripStateManager->method('getRequest')->willReturn(null);
        $this->messageBus->method('dispatch')->willReturnCallback(static fn (object $msg): Envelope => new Envelope($msg));

        $this->objectMapper->method('map')->willReturn(new StageResponse());

        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'index' => 0]);

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
        $this->tripStateManager->method('getRequest')->willReturn(null);
        $this->messageBus->method('dispatch')->willReturnCallback(static fn (object $msg): Envelope => new Envelope($msg));

        $expectedResponse = new StageResponse();
        $this->objectMapper->expects($this->once())
            ->method('map')
            ->with(
                $this->callback(static fn (Stage $s): bool => $s->isRestDay && $s->tripId === 'trip-1'),
                StageResponse::class,
            )
            ->willReturn($expectedResponse);

        $result = $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'index' => 0]);

        $this->assertSame($expectedResponse, $result);
    }

    #[Test]
    public function dispatchesRecalculateStagesWithFullRange(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);
        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);
        $stage1 = new Stage(tripId: 'trip-1', dayNumber: 2, distance: 90.0, elevation: 600.0, startPoint: $coord, endPoint: $coord);
        $stage2 = new Stage(tripId: 'trip-1', dayNumber: 3, distance: 70.0, elevation: 400.0, startPoint: $coord, endPoint: $coord);

        $this->tripStateManager->method('getStages')->willReturn([$stage0, $stage1, $stage2]);
        $this->tripStateManager->method('getRequest')->willReturn(null);

        $dispatchedMessages = [];
        $this->messageBus->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $msg;

                return new Envelope($msg);
            });

        $this->objectMapper->method('map')->willReturn(new StageResponse());

        // Insert after index 0 → rest day at position 1, affected indices = [1, 2, 3]
        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'index' => 0]);

        $recalculate = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof RecalculateStages));
        $this->assertCount(1, $recalculate);
        $this->assertSame('trip-1', $recalculate[0]->tripId);
        // After inserting at index 0, stages are [0..3], inserted at 1, so affected = [1,2,3]
        $this->assertSame([1, 2, 3], $recalculate[0]->affectedIndices);
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

        $this->objectMapper->method('map')->willReturn(new StageResponse());

        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'index' => 0]);

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
        $tripRequest->startDate = new \DateTimeImmutable('2026-06-01');

        $this->tripStateManager->method('getStages')->willReturn([$stage0]);
        $this->tripStateManager->method('getRequest')->willReturn($tripRequest);

        $dispatchedMessages = [];
        $this->messageBus->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $msg;

                return new Envelope($msg);
            });

        $this->objectMapper->method('map')->willReturn(new StageResponse());

        $this->processor->process(null, new Post(), ['tripId' => 'trip-1', 'index' => 0]);

        $weatherMessages = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof FetchWeather));
        $calendarMessages = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof CheckCalendar));
        $this->assertCount(1, $weatherMessages);
        $this->assertSame('trip-1', $weatherMessages[0]->tripId);
        $this->assertCount(1, $calendarMessages);
        $this->assertSame('trip-1', $calendarMessages[0]->tripId);
    }
}
