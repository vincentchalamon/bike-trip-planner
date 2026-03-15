<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Delete;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Engine\DistanceCalculatorInterface;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\State\StageDeleteProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class StageDeleteProcessorTest extends TestCase
{
    private MockObject&TripRequestRepositoryInterface $tripStateManager;

    private MockObject&MessageBusInterface $messageBus;

    private MockObject&DistanceCalculatorInterface $distanceCalculator;

    private StageDeleteProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        $this->tripStateManager = $this->createMock(TripRequestRepositoryInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->distanceCalculator = $this->createMock(DistanceCalculatorInterface::class);

        $this->processor = new StageDeleteProcessor(
            $this->tripStateManager,
            $this->messageBus,
            $this->distanceCalculator,
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
}
