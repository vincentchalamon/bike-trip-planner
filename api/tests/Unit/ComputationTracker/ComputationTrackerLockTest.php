<?php

declare(strict_types=1);

namespace App\Tests\Unit\ComputationTracker;

use App\ComputationTracker\ComputationTracker;
use App\Enum\ComputationName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The decisions that have to be made under the per-trip status lock, and not before it.
 *
 * A real race cannot be scheduled from a unit test, so the lock store plays the other worker:
 * the first time this tracker asks for the lock, a second tracker on the same cache writes what
 * a concurrent worker would have written while this one was waiting. Whatever this tracker read
 * before asking is now stale; only a decision taken after acquiring sees the truth.
 */
final class ComputationTrackerLockTest extends TestCase
{
    private ArrayAdapter $cache;

    private ComputationTracker $other;

    #[\Override]
    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->other = new ComputationTracker($this->cache, new LockFactory(new InMemoryStore()));
    }

    #[Test]
    public function aComputationStartedWhileWaitingForTheLockIsNotRearmed(): void
    {
        $this->other->initializeComputations('trip-1', [ComputationName::WEATHER]);
        $this->other->markDone('trip-1', ComputationName::WEATHER);

        $tracker = $this->trackerRacedBy(function (): void {
            $this->other->markRunning('trip-1', ComputationName::WEATHER);
        });

        self::assertFalse($tracker->rearmIfSettled('trip-1', ComputationName::WEATHER));
        self::assertSame('running', $tracker->getStatuses('trip-1')['weather'] ?? null);
    }

    #[Test]
    public function aSettledComputationIsStillRearmed(): void
    {
        $this->other->initializeComputations('trip-1', [ComputationName::WEATHER]);
        $this->other->markFailed('trip-1', ComputationName::WEATHER);

        $tracker = $this->trackerRacedBy(static function (): void {
        });

        self::assertTrue($tracker->rearmIfSettled('trip-1', ComputationName::WEATHER));
        self::assertSame('pending', $tracker->getStatuses('trip-1')['weather'] ?? null);
    }

    #[Test]
    public function onlyOneWorkerClaimsTheReadyPublication(): void
    {
        $otherClaimed = null;
        $tracker = $this->trackerRacedBy(function () use (&$otherClaimed): void {
            $otherClaimed = $this->other->claimReadyPublication('trip-1', 1);
        });

        $claimed = $tracker->claimReadyPublication('trip-1', 1);

        self::assertTrue($otherClaimed, 'The concurrent worker never ran: the claim did not take the lock.');
        self::assertFalse($claimed);
    }

    private function trackerRacedBy(\Closure $concurrentWrite): ComputationTracker
    {
        $store = new class ($concurrentWrite) implements PersistingStoreInterface {
            private readonly InMemoryStore $inner;

            private ?\Closure $pending;

            public function __construct(\Closure $concurrentWrite)
            {
                $this->inner = new InMemoryStore();
                $this->pending = $concurrentWrite;
            }

            public function save(Key $key): void
            {
                $write = $this->pending;
                $this->pending = null;
                if ($write instanceof \Closure) {
                    $write();
                }

                $this->inner->save($key);
            }

            public function delete(Key $key): void
            {
                $this->inner->delete($key);
            }

            public function exists(Key $key): bool
            {
                return $this->inner->exists($key);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                $this->inner->putOffExpiration($key, $ttl);
            }
        };

        return new ComputationTracker($this->cache, new LockFactory($store));
    }
}
