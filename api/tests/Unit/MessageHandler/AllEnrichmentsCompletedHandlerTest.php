<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AllEnrichmentsCompleted;
use App\MessageHandler\AllEnrichmentsCompletedHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Validates the minimal fallback behaviour of the gate's terminal handler.
 *
 * The full LLaMA pipeline (issues #301-#303) is not wired yet; until then,
 * this handler must publish the `TRIP_READY` Mercure event directly so the
 * frontend receives the terminal signal. When the AI pipeline lands, the
 * downstream LLaMA handler will own that publication instead.
 */
final class AllEnrichmentsCompletedHandlerTest extends TestCase
{
    #[Test]
    public function publishesTripReadyWithStagesAndStatusFromTracker(): void
    {
        $tripId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

        $stage = new Stage(
            tripId: $tripId,
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
        );

        $statuses = ['route' => 'done', 'stages' => 'done', 'weather' => 'failed'];

        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $tracker->method('getStatuses')->willReturn($statuses);
        $tracker->method('getProgress')->willReturn([
            'completed' => 2,
            'failed' => 1,
            'total' => 3,
        ]);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects(self::once())
            ->method('publishTripReady')
            ->with(
                $tripId,
                self::callback(static fn (array $stages): bool => 1 === \count($stages) && $stages[0] instanceof Stage),
                self::callback(static fn (array $summary): bool => ['route' => 'done', 'stages' => 'done', 'weather' => 'failed'] === $summary['status']),
            );

        $handler = new AllEnrichmentsCompletedHandler(
            $tracker,
            $publisher,
            $tripStateManager,
            new NullLogger(),
        );

        $handler(new AllEnrichmentsCompleted($tripId));
    }

    #[Test]
    public function tolerantWhenStagesRepositoryReturnsNull(): void
    {
        $tripId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $tracker->method('getStatuses')->willReturn([]);
        $tracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 0]);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects(self::once())
            ->method('publishTripReady')
            ->with(
                $tripId,
                [],
                self::callback(static fn (array $summary): bool => [] === $summary['status']),
            );

        $handler = new AllEnrichmentsCompletedHandler(
            $tracker,
            $publisher,
            $tripStateManager,
            new NullLogger(),
        );

        $handler(new AllEnrichmentsCompleted($tripId));
    }
}
