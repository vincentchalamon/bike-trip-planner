<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\ComputationTracker\ComputationTracker;
use App\Enum\ComputationName;
use App\Message\AllEnrichmentsCompleted;
use App\Mercure\TripUpdatePublisherInterface;
use App\Service\TripCompletionGate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class TripCompletionGateTest extends TestCase
{
    private const string TRIP_ID = 'trip-1';

    private ComputationTracker $tracker;

    #[\Override]
    protected function setUp(): void
    {
        $this->tracker = new ComputationTracker(new ArrayAdapter());
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

    private function gate(TripUpdatePublisherInterface $publisher, MessageBusInterface $bus): TripCompletionGate
    {
        return new TripCompletionGate($this->tracker, $publisher, $bus);
    }
}
