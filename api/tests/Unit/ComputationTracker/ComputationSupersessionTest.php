<?php

declare(strict_types=1);

namespace App\Tests\Unit\ComputationTracker;

use App\ComputationTracker\ComputationSupersession;
use App\ComputationTracker\ComputationTracker;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\TripUpdatePublisherInterface;
use App\Service\TripCompletionGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Settling the work a generation bump abandoned (ADR-073).
 */
#[CoversClass(ComputationSupersession::class)]
final class ComputationSupersessionTest extends TestCase
{
    private const string TRIP_ID = 'trip-1';

    private ComputationTracker $tracker;

    #[\Override]
    protected function setUp(): void
    {
        $this->tracker = new ComputationTracker(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
    }

    /**
     * The point of the class: what the new generation is not re-running is abandoned, and the
     * gate can close again. Left `pending`, a single one of these held `settled === total` out
     * of reach for good.
     */
    #[Test]
    public function whatTheNewGenerationIsNotRerunningIsSettled(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::POIS,
            ComputationName::TERRAIN,
        ]);
        $this->tracker->markDone(self::TRIP_ID, ComputationName::POIS);

        $this->supersession()->settleWhatWasNotRedispatched(self::TRIP_ID, [ComputationName::POIS]);

        self::assertSame(
            ['pois' => 'done', 'terrain' => 'superseded'],
            $this->tracker->getStatuses(self::TRIP_ID),
        );

        $progress = $this->tracker->getProgress(self::TRIP_ID);
        self::assertSame($progress['total'], $progress['settled']);
    }

    /**
     * The race this design exists to avoid, from the other side: a computation the newer
     * generation has already finished must not be reported as abandoned. It is why the write
     * is a compare-and-set and why it happens at the bump rather than at the consume.
     */
    #[Test]
    public function aComputationTheNewGenerationAlreadySettledIsLeftAlone(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [ComputationName::POIS]);
        $this->tracker->markDone(self::TRIP_ID, ComputationName::POIS);

        // POIS is not in the dispatched set, so it is a candidate — but it is terminal.
        $this->supersession()->settleWhatWasNotRedispatched(self::TRIP_ID, []);

        self::assertSame(['pois' => 'done'], $this->tracker->getStatuses(self::TRIP_ID));
    }

    /**
     * Wind and fords are dispatched only at the end of FetchWeatherHandler. If the weather is
     * abandoned they are never dispatched either, and would hold the total out of reach — the
     * same cascade, and the same reason, as ComputationFailureSubscriber.
     */
    #[Test]
    public function abandoningTheWeatherAbandonsWhatOnlyItWouldHaveDispatched(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::WEATHER,
            ComputationName::WIND,
            ComputationName::FORDS,
        ]);

        $this->supersession()->settleWhatWasNotRedispatched(self::TRIP_ID, []);

        self::assertSame(
            ['weather' => 'superseded', 'wind' => 'superseded', 'fords' => 'superseded'],
            $this->tracker->getStatuses(self::TRIP_ID),
        );
    }

    /**
     * Once with the list, not once per computation: a client needs to learn that this much
     * stopped being computed, and the envelope already carries the version that superseded it.
     */
    #[Test]
    public function theAnnouncementIsMadeOnceWithTheWholeList(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::POIS,
            ComputationName::TERRAIN,
        ]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publishComputationsSuperseded')
            ->with(self::TRIP_ID, [ComputationName::POIS, ComputationName::TERRAIN]);

        $this->supersession($publisher)->settleWhatWasNotRedispatched(self::TRIP_ID, []);
    }

    /**
     * An edit that re-runs everything it invalidated has abandoned nothing, and must not tell
     * a client otherwise.
     */
    #[Test]
    public function nothingIsAnnouncedWhenNothingWasAbandoned(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [ComputationName::POIS]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publishComputationsSuperseded');

        $this->supersession($publisher)->settleWhatWasNotRedispatched(self::TRIP_ID, [ComputationName::POIS]);
    }

    private function supersession(?TripUpdatePublisherInterface $publisher = null): ComputationSupersession
    {
        $publisher ??= $this->createStub(TripUpdatePublisherInterface::class);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        return new ComputationSupersession(
            $this->tracker,
            $publisher,
            new TripCompletionGate(
                $this->tracker,
                $publisher,
                $bus,
                $this->createStub(TripGenerationTrackerInterface::class),
            ),
            new NullLogger(),
        );
    }
}
