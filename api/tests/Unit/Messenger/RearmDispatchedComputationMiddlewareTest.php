<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\ComputationTracker\ComputationTracker;
use App\Enum\ComputationName;
use App\Message\AllEnrichmentsCompleted;
use App\Message\RecalculateStages;
use App\Message\ScanPois;
use App\Messenger\RearmDispatchedComputationMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;

/**
 * A computation with a message in flight never reads as terminal (ADR-073).
 */
#[CoversClass(RearmDispatchedComputationMiddleware::class)]
final class RearmDispatchedComputationMiddlewareTest extends TestCase
{
    private const string TRIP_ID = 'trip-1';

    private ComputationTracker $tracker;

    #[\Override]
    protected function setUp(): void
    {
        $this->tracker = new ComputationTracker(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
    }

    /**
     * The case that makes the supersession safe: a computation marked `superseded` because its
     * caller did not re-dispatch it, then re-dispatched anyway by a cascade the caller could
     * not see.
     */
    #[Test]
    public function dispatchingARedispatchedComputationPutsItBackInFlight(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [ComputationName::POIS]);
        $this->tracker->markSupersededUnlessSettled(self::TRIP_ID, ComputationName::POIS);

        $this->middleware()->handle(
            new Envelope(new ScanPois(self::TRIP_ID, generation: 2)),
            $this->passThrough(),
        );

        self::assertSame(['pois' => 'pending'], $this->tracker->getStatuses(self::TRIP_ID));
    }

    /**
     * Consuming is not dispatching. The handler arms its own computation through
     * `executeWithTracking()`, and re-arming on the way in would undo its `markRunning`.
     */
    #[Test]
    public function consumingAMessageArmsNothing(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [ComputationName::POIS]);
        $this->tracker->markDone(self::TRIP_ID, ComputationName::POIS);

        $this->middleware()->handle(
            new Envelope(new ScanPois(self::TRIP_ID, generation: 2), [new ConsumedByWorkerStamp()]),
            $this->passThrough(),
        );

        self::assertSame(['pois' => 'done'], $this->tracker->getStatuses(self::TRIP_ID));
    }

    /**
     * A running computation is already armed, and a write would only be noise — this runs on
     * every dispatch of the pipeline.
     */
    #[Test]
    public function aComputationAlreadyInFlightIsLeftAlone(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [ComputationName::POIS]);
        $this->tracker->markRunning(self::TRIP_ID, ComputationName::POIS);

        $this->middleware()->handle(
            new Envelope(new ScanPois(self::TRIP_ID)),
            $this->passThrough(),
        );

        self::assertSame(['pois' => 'running'], $this->tracker->getStatuses(self::TRIP_ID));
    }

    /**
     * `RecalculateStages` and the terminal message carry a trip but track no computation of
     * their own, so there is no status to arm.
     */
    #[Test]
    public function aMessageThatTracksNoComputationChangesNothing(): void
    {
        $this->tracker->initializeComputations(self::TRIP_ID, [ComputationName::POIS]);
        $this->tracker->markSupersededUnlessSettled(self::TRIP_ID, ComputationName::POIS);

        foreach ([new RecalculateStages(self::TRIP_ID, []), new AllEnrichmentsCompleted(self::TRIP_ID)] as $message) {
            $this->middleware()->handle(new Envelope($message), $this->passThrough());
        }

        self::assertSame(['pois' => 'superseded'], $this->tracker->getStatuses(self::TRIP_ID));
    }

    private function middleware(): RearmDispatchedComputationMiddleware
    {
        return new RearmDispatchedComputationMiddleware($this->tracker);
    }

    private function passThrough(): StackInterface
    {
        return new readonly class () implements StackInterface {
            #[\Override]
            public function next(): MiddlewareInterface
            {
                return new readonly class () implements MiddlewareInterface {
                    #[\Override]
                    public function handle(Envelope $envelope, StackInterface $stack): Envelope
                    {
                        return $envelope;
                    }
                };
            }
        };
    }
}
