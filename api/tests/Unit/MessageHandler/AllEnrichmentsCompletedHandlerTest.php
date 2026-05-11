<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Llm\LlmClientInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AllEnrichmentsCompleted;
use App\Message\AnalyzeStageWithLlmMessage;
use App\MessageHandler\AllEnrichmentsCompletedHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

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
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $this->disabledLlmClient(),
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
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $this->disabledLlmClient(),
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
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $this->disabledLlmClient(),
        );

        $handler(new AllEnrichmentsCompleted($tripId));
    }

    #[Test]
    public function dispatchesAnalyzeStageWithLlmMessageForEachNonRestStageWhenLlmEnabled(): void
    {
        $tripId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

        $stage1 = new Stage(
            tripId: $tripId,
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
        );
        $restStage = new Stage(
            tripId: $tripId,
            dayNumber: 2,
            distance: 0.0,
            elevation: 0.0,
            startPoint: new Coordinate(48.5, 2.5),
            endPoint: new Coordinate(48.5, 2.5),
            isRestDay: true,
        );
        $stage3 = new Stage(
            tripId: $tripId,
            dayNumber: 3,
            distance: 90.0,
            elevation: 700.0,
            startPoint: new Coordinate(48.5, 2.5),
            endPoint: new Coordinate(49.0, 3.0),
        );

        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $tracker->method('claimReadyPublication')->willReturn(true);
        $tracker->method('getStatuses')->willReturn(['route' => 'done']);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage1, $restStage, $stage3]);

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))
            ->method('dispatch')
            ->with(self::callback(
                static fn (object $message): bool => $message instanceof AnalyzeStageWithLlmMessage
                    && \in_array($message->dayNumber, [1, 3], true),
            ))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $llmClient = $this->createStub(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);

        $handler = new AllEnrichmentsCompletedHandler(
            $tracker,
            $publisher,
            $tripStateManager,
            new NullLogger(),
            $bus,
            $llmClient,
        );

        $handler(new AllEnrichmentsCompleted($tripId));
    }

    #[Test]
    public function doesNotDispatchAnalyzeStageMessagesWhenLlmDisabled(): void
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

        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $tracker->method('claimReadyPublication')->willReturn(true);
        $tracker->method('getStatuses')->willReturn([]);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new AllEnrichmentsCompletedHandler(
            $tracker,
            $publisher,
            $tripStateManager,
            new NullLogger(),
            $bus,
            $this->disabledLlmClient(),
        );

        $handler(new AllEnrichmentsCompleted($tripId));
    }

    private function disabledLlmClient(): LlmClientInterface
    {
        $llmClient = $this->createStub(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(false);

        return $llmClient;
    }
}
