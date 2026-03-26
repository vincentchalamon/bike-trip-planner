<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use Override;
use DateTimeImmutable;
use ApiPlatform\Metadata\Delete;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\ApiResource\TripRequest;
use App\Repository\TripRequestRepositoryInterface;
use App\State\StageDeleteProcessor;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class StageDeleteProcessorTest extends TestCase
{
    private MockObject&TripRequestRepositoryInterface $tripStateManager;

    private MockObject&MessageBusInterface $messageBus;

    private Stub $distanceCalculator;

    private StageDeleteProcessor $processor;

    #[Override]
    protected function setUp(): void
    {
        $this->tripStateManager = $this->createMock(TripRequestRepositoryInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(2);

        // Return a non-locked request by default (startDate in the future)
        $unlockedRequest = new TripRequest();
        $unlockedRequest->startDate = new DateTimeImmutable('+30 days');
        $this->tripStateManager->method('getRequest')->willReturn($unlockedRequest);

        $this->processor = new StageDeleteProcessor(
            $this->tripStateManager,
            $this->messageBus,
            $this->distanceCalculator,
            $generationTracker,
            new TripLocker(),
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

        $this->processor->process(null, new Delete(), ['tripId' => 'trip-1', 'index' => 1]);

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

        $this->processor->process(null, new Delete(), ['tripId' => 'trip-1', 'index' => 1]);

        $recalculate = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof RecalculateStages));
        $this->assertCount(1, $recalculate);
        $this->assertSame('trip-1', $recalculate[0]->tripId);
        $this->assertSame([], $recalculate[0]->affectedIndices);
        // Geographic scans must be skipped: deleting a rest day does not change geography
        $this->assertTrue($recalculate[0]->skipGeographicScans);
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

        $this->processor->process(null, new Delete(), ['tripId' => 'trip-1', 'index' => 1]);

        $recalculate = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof RecalculateStages));
        $this->assertCount(1, $recalculate);
        // Geographic scans must NOT be skipped: deleting a regular stage changes geography
        $this->assertFalse($recalculate[0]->skipGeographicScans);
    }

    #[Test]
    public function deletingRestDayAlwaysDispatchesWeatherAndCalendar(): void
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);

        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);
        $restDay = new Stage(tripId: 'trip-1', dayNumber: 2, distance: 0.0, elevation: 0.0, startPoint: $coord, endPoint: $coord, isRestDay: true);
        $stage2 = new Stage(tripId: 'trip-1', dayNumber: 3, distance: 90.0, elevation: 600.0, startPoint: $coord, endPoint: $coord);

        $this->tripStateManager->method('getStages')->willReturn([$stage0, $restDay, $stage2]);
        $this->tripStateManager->method('getSourceType')->willReturn(null);

        $dispatchedMessages = [];
        $this->messageBus->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $msg;

                return new Envelope($msg);
            });

        $this->processor->process(null, new Delete(), ['tripId' => 'trip-1', 'index' => 1]);

        $weatherMessages = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof FetchWeather));
        $calendarMessages = array_values(array_filter($dispatchedMessages, static fn (object $m): bool => $m instanceof CheckCalendar));
        $this->assertCount(1, $weatherMessages);
        $this->assertSame('trip-1', $weatherMessages[0]->tripId);
        $this->assertCount(1, $calendarMessages);
        $this->assertSame('trip-1', $calendarMessages[0]->tripId);
    }

    #[Test]
    public function lockedTripThrowsHttpException(): void
    {
        $lockedRequest = new TripRequest();
        $lockedRequest->startDate = new DateTimeImmutable('yesterday');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($lockedRequest);
        $tripStateManager->method('getStages')->willReturn([]);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(1);

        $processor = new StageDeleteProcessor(
            $tripStateManager,
            $this->createStub(MessageBusInterface::class),
            $this->createStub(DistanceCalculatorInterface::class),
            $generationTracker,
            new TripLocker(),
        );

        try {
            $processor->process(null, new Delete(), ['tripId' => 'trip-1', 'index' => 0]);
            self::fail('Expected HttpException to be thrown.');
        } catch (HttpException $httpException) {
            self::assertSame(423, $httpException->getStatusCode());
        }
    }
}
