<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Mercure\MercureSubscriptionCheckerInterface;
use App\Message\AllEnrichmentsCompleted;
use App\MessageHandler\AllEnrichmentsCompletedHandler;
use App\Notification\AnalysisNotifier;
use App\Notification\NotificationDispatcherInterface;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Validates the gate's terminal handler: once every enrichment settles it
 * publishes `TRIP_READY` directly with the stages and per-block status.
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
        $tracker->method('claimReadyPublication')->willReturn(true);
        $tracker->method('getStatuses')->willReturn($statuses);

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
            $this->noopAnalysisNotifier(),
            new NullLogger(),
        );

        $handler(new AllEnrichmentsCompleted($tripId));
    }

    #[Test]
    public function skipsWhenReadyPublicationAlreadyClaimed(): void
    {
        $tripId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $tracker->method('claimReadyPublication')->willReturn(false);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects(self::never())->method('publishTripReady');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);

        $handler = new AllEnrichmentsCompletedHandler(
            $tracker,
            $publisher,
            $tripStateManager,
            $this->noopAnalysisNotifier(),
            new NullLogger(),
        );

        $handler(new AllEnrichmentsCompleted($tripId));
    }

    #[Test]
    public function tolerantWhenStagesRepositoryReturnsNull(): void
    {
        $tripId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $tracker->method('claimReadyPublication')->willReturn(true);
        $tracker->method('getStatuses')->willReturn([]);

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
            $this->noopAnalysisNotifier(),
            new NullLogger(),
        );

        $handler(new AllEnrichmentsCompleted($tripId));
    }

    /**
     * A no-op notifier (anonymous trip => nothing pushed); the analysis-push path
     * is covered on its own in {@see \App\Tests\Unit\Notification\AnalysisNotifierTest}.
     */
    private function noopAnalysisNotifier(): AnalysisNotifier
    {
        $tripRepository = $this->createStub(TripRequestRepositoryInterface::class);
        $tripRepository->method('getOwnerId')->willReturn(null);

        return new AnalysisNotifier(
            $tripRepository,
            $this->createStub(MercureSubscriptionCheckerInterface::class),
            $this->createStub(NotificationDispatcherInterface::class),
            $this->createStub(TranslatorInterface::class),
        );
    }
}
