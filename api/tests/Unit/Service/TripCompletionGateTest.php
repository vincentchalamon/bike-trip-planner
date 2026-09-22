<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\ComputationTracker\ComputationTracker;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Message\AllEnrichmentsCompleted;
use App\Mercure\TripUpdatePublisherInterface;
use App\Service\TripCompletionGate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class TripCompletionGateTest extends TestCase
{
    private const string TRIP_ID = 'trip-1';

    private ComputationTracker $tracker;

    #[\Override]
    protected function setUp(): void
    {
        $this->tracker = new ComputationTracker(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
    }

    #[Test]
    public function publishesTerminalEventWhenEveryComputationHasSettled(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::ROUTE,
            ComputationName::STAGES,
        ]);
        $this->tracker->markDone(self::TRIP_ID, ComputationName::ROUTE);
        $this->tracker->markFailed(self::TRIP_ID, ComputationName::STAGES);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publishTripComplete')
            ->with(self::TRIP_ID, ['route' => 'done', 'stages' => 'failed']);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(AllEnrichmentsCompleted::class))
            ->willReturn(new Envelope(new AllEnrichmentsCompleted(self::TRIP_ID)));

        $this->gate($publisher, $bus)->evaluate(self::TRIP_ID);
    }

    #[Test]
    public function doesNothingWhileAComputationIsStillPending(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::ROUTE,
            ComputationName::STAGES,
        ]);
        $this->tracker->markDone(self::TRIP_ID, ComputationName::ROUTE);
        // STAGES still pending.

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publishTripComplete');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $this->gate($publisher, $bus)->evaluate(self::TRIP_ID);
    }

    #[Test]
    public function doesNothingForAnUnknownTrip(): void
    {
        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publishTripComplete');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $this->gate($publisher, $bus)->evaluate('unknown-trip');
    }

    /**
     * A computation the trip moved past is terminal, so it closes the gate like any other —
     * which is the whole point: left `pending`, one superseded message made the settled
     * condition unreachable for good (ADR-073).
     */
    #[Test]
    public function aSupersededComputationSettlesTheGateLikeAnyOtherTerminalOne(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::ROUTE,
            ComputationName::STAGES,
        ]);
        $this->tracker->markDone(self::TRIP_ID, ComputationName::ROUTE);
        $this->tracker->markSupersededUnlessSettled(self::TRIP_ID, ComputationName::STAGES);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publishTripComplete')
            ->with(self::TRIP_ID, ['route' => 'done', 'stages' => 'superseded']);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturn(new Envelope(new AllEnrichmentsCompleted(self::TRIP_ID)));

        $this->gate($publisher, $bus)->evaluate(self::TRIP_ID);
    }

    /**
     * The terminal message names the generation that settled, so the publication can be
     * claimed per generation instead of once per trip.
     */
    #[Test]
    public function theTerminalMessageCarriesTheGenerationThatSettled(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [ComputationName::ROUTE]);
        $this->tracker->markDone(self::TRIP_ID, ComputationName::ROUTE);

        $dispatched = null;
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        $this->gate($this->createStub(TripUpdatePublisherInterface::class), $bus, generation: 7)
            ->evaluate(self::TRIP_ID);

        $this->assertInstanceOf(AllEnrichmentsCompleted::class, $dispatched);
        $this->assertSame(7, $dispatched->generation);
    }

    private function gate(
        TripUpdatePublisherInterface $publisher,
        MessageBusInterface $bus,
        ?int $generation = null,
    ): TripCompletionGate {
        $generations = $this->createStub(TripGenerationTrackerInterface::class);
        $generations->method('current')->willReturn($generation);

        return new TripCompletionGate($this->tracker, $publisher, $bus, $generations);
    }
}
