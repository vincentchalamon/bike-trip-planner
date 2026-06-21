<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\ComputationTracker\ComputationTracker;
use App\Enum\ComputationName;
use App\EventListener\ComputationFailureSubscriber;
use App\Message\AllEnrichmentsCompleted;
use App\Message\AnalyzeTerrain;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Mercure\TripUpdatePublisherInterface;
use App\Service\TripCompletionGate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Proves the terminal-gate reliability fix (recette #649, Lot 1):
 *  - a computation whose retries are exhausted is marked `failed`, unblocking the gate;
 *  - a computation that will still be retried is left untouched (no premature gate);
 *  - a weather failure cascades to wind/fords so the gate total stays reachable.
 */
final class ComputationFailureSubscriberTest extends TestCase
{
    private const string TRIP_ID = 'trip-1';

    private ComputationTracker $tracker;

    #[\Override]
    protected function setUp(): void
    {
        $this->tracker = new ComputationTracker(new ArrayAdapter());
    }

    #[Test]
    public function marksComputationFailedAndFiresGateWhenRetriesAreExhausted(): void
    {
        // Only TERRAIN is left running; every other computation already settled.
        $this->tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::ROUTE,
            ComputationName::TERRAIN,
        ]);
        $this->tracker->markDone(self::TRIP_ID, ComputationName::ROUTE);
        $this->tracker->markRunning(self::TRIP_ID, ComputationName::TERRAIN);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())->method('publishTripComplete');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(AllEnrichmentsCompleted::class))
            ->willReturn(new Envelope(new AllEnrichmentsCompleted(self::TRIP_ID)));

        ($this->subscriber($publisher, $bus))($this->exhaustedFailure(new AnalyzeTerrain(self::TRIP_ID)));

        self::assertSame('failed', $this->statusOf(ComputationName::TERRAIN));
        self::assertSame(
            ['completed' => 1, 'failed' => 1, 'total' => 2],
            $this->tracker->getProgress(self::TRIP_ID),
        );
    }

    #[Test]
    public function doesNothingWhileARetryIsStillScheduled(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::ROUTE,
            ComputationName::TERRAIN,
        ]);
        $this->tracker->markDone(self::TRIP_ID, ComputationName::ROUTE);
        $this->tracker->markRunning(self::TRIP_ID, ComputationName::TERRAIN);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publishTripComplete');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        // The retry listener decided to retry: willRetry() === true.
        ($this->subscriber($publisher, $bus))($this->retryableFailure(new AnalyzeTerrain(self::TRIP_ID)));

        // TERRAIN is left running so a future attempt can still settle it.
        self::assertSame('running', $this->statusOf(ComputationName::TERRAIN));
    }

    #[Test]
    public function weatherFailureCascadesToWindAndFordsAndReachesTheTotal(): void
    {
        // The full settled pipeline minus weather/wind/fords, which never settled
        // because weather failed before dispatching wind and fords.
        $this->tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::ROUTE,
            ComputationName::WEATHER,
            ComputationName::WIND,
            ComputationName::FORDS,
        ]);
        $this->tracker->markDone(self::TRIP_ID, ComputationName::ROUTE);
        $this->tracker->markRunning(self::TRIP_ID, ComputationName::WEATHER);
        // WIND and FORDS were never dispatched: they stay pending.

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())->method('publishTripComplete');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(AllEnrichmentsCompleted::class))
            ->willReturn(new Envelope(new AllEnrichmentsCompleted(self::TRIP_ID)));

        ($this->subscriber($publisher, $bus))($this->exhaustedFailure(new FetchWeather(self::TRIP_ID)));

        self::assertSame('failed', $this->statusOf(ComputationName::WEATHER));
        self::assertSame('failed', $this->statusOf(ComputationName::WIND), 'wind cascades when weather fails');
        self::assertSame('failed', $this->statusOf(ComputationName::FORDS), 'fords cascades when weather fails');
        self::assertSame(
            ['completed' => 1, 'failed' => 3, 'total' => 4],
            $this->tracker->getProgress(self::TRIP_ID),
        );
    }

    /**
     * Parity guard: resolveComputation() is driven by the explicit
     * {@see ComputationFailureSubscriber::MESSAGE_TO_COMPUTATION} table. If a new
     * computation is added to {@see ComputationName::pipeline()} without a matching
     * entry here, its failure would stay `pending` forever and the gate could never
     * settle. This test fails the moment the table drifts from the pipeline.
     */
    #[Test]
    public function resolveComputationCoversAllPipelineEntries(): void
    {
        $mapped = array_values(ComputationFailureSubscriber::MESSAGE_TO_COMPUTATION);

        $missing = array_filter(
            ComputationName::pipeline(),
            static fn (ComputationName $c): bool => !\in_array($c, $mapped, true),
        );
        self::assertSame([], array_values($missing), sprintf(
            'These ComputationName::pipeline() entries have no MESSAGE_TO_COMPUTATION mapping: %s',
            implode(', ', array_map(static fn (ComputationName $c): string => $c->value, $missing)),
        ));

        $extraneous = array_filter(
            $mapped,
            static fn (ComputationName $c): bool => !\in_array($c, ComputationName::pipeline(), true),
        );
        self::assertSame([], array_values($extraneous), sprintf(
            'These MESSAGE_TO_COMPUTATION entries are not part of ComputationName::pipeline(): %s',
            implode(', ', array_map(static fn (ComputationName $c): string => $c->value, $extraneous)),
        ));
    }

    #[Test]
    public function ignoresMessagesThatAreNotPartOfTheGatedPipeline(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [ComputationName::STAGES]);
        $this->tracker->markRunning(self::TRIP_ID, ComputationName::STAGES);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publishTripComplete');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        // RecalculateStages is an Act 3 inline-edit message, not a gated pipeline computation.
        ($this->subscriber($publisher, $bus))($this->exhaustedFailure(new RecalculateStages(self::TRIP_ID, [0])));

        self::assertSame('running', $this->statusOf(ComputationName::STAGES));
    }

    private function statusOf(ComputationName $computation): string
    {
        $statuses = $this->tracker->getStatuses(self::TRIP_ID);
        self::assertNotNull($statuses);
        self::assertArrayHasKey($computation->value, $statuses);

        return $statuses[$computation->value];
    }

    private function subscriber(TripUpdatePublisherInterface $publisher, MessageBusInterface $bus): ComputationFailureSubscriber
    {
        return new ComputationFailureSubscriber(
            $this->tracker,
            new TripCompletionGate($this->tracker, $publisher, $bus),
            new NullLogger(),
        );
    }

    /**
     * Builds a failure event whose retries are exhausted (the retry listener did
     * not call setForRetry(), so willRetry() stays false).
     */
    private function exhaustedFailure(object $message): WorkerMessageFailedEvent
    {
        $envelope = new Envelope($message, [new RedeliveryStamp(3)]);

        return new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('boom'));
    }

    /**
     * Builds a failure event that the retry listener flagged for retry.
     */
    private function retryableFailure(object $message): WorkerMessageFailedEvent
    {
        $envelope = new Envelope($message, [new RedeliveryStamp(1)]);
        $event = new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('boom'));
        $event->setForRetry();

        return $event;
    }
}
