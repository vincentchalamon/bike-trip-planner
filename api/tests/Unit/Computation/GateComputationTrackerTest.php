<?php

declare(strict_types=1);

namespace App\Tests\Unit\Computation;

use App\ComputationTracker\ComputationTracker;
use App\Enum\ComputationName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Targets the gate semantics added in issue #299:
 *
 * - {@see ComputationTracker::getProgress()} returns up-to-date counters that
 *   power the `computation_step_completed` Mercure event.
 * - {@see ComputationTracker::claimReadyPublication()} implements a best-effort
 *   idempotency guard so only one worker publishes the terminal TRIP_READY event.
 *
 * The tests use the in-memory {@see ArrayAdapter} so they remain fast and free
 * of any Redis/Predis dependency while exercising the same {@see CacheItemPoolInterface}
 * contract used in production.
 */
final class GateComputationTrackerTest extends TestCase
{
    private ComputationTracker $tracker;

    #[\Override]
    protected function setUp(): void
    {
        $this->tracker = new ComputationTracker(new ArrayAdapter());
    }

    #[Test]
    public function getProgressReturnsZeroesForUnknownTrip(): void
    {
        self::assertSame(
            ['completed' => 0, 'failed' => 0, 'total' => 0],
            $this->tracker->getProgress('unknown-trip'),
        );
    }

    #[Test]
    public function getProgressReportsTotalsImmediatelyAfterInitialization(): void
    {
        $this->tracker->initializeComputations('trip-1', [
            ComputationName::ROUTE,
            ComputationName::STAGES,
            ComputationName::WEATHER,
        ]);

        self::assertSame(
            ['completed' => 0, 'failed' => 0, 'total' => 3],
            $this->tracker->getProgress('trip-1'),
        );
    }

    #[Test]
    public function getProgressIncrementsCompletedAndFailedSeparately(): void
    {
        $this->tracker->initializeComputations('trip-1', [
            ComputationName::ROUTE,
            ComputationName::STAGES,
            ComputationName::WEATHER,
        ]);

        $this->tracker->markDone('trip-1', ComputationName::ROUTE);
        $this->tracker->markFailed('trip-1', ComputationName::STAGES);
        // WEATHER still pending

        self::assertSame(
            ['completed' => 1, 'failed' => 1, 'total' => 3],
            $this->tracker->getProgress('trip-1'),
        );
    }

    #[Test]
    public function getProgressIgnoresRunningStatusForCompletedCount(): void
    {
        $this->tracker->initializeComputations('trip-1', [
            ComputationName::ROUTE,
            ComputationName::STAGES,
        ]);

        $this->tracker->markRunning('trip-1', ComputationName::ROUTE);
        $this->tracker->markRunning('trip-1', ComputationName::STAGES);

        self::assertSame(
            ['completed' => 0, 'failed' => 0, 'total' => 2],
            $this->tracker->getProgress('trip-1'),
        );
    }

    #[Test]
    public function getProgressTracksEachIndependentTrip(): void
    {
        $this->tracker->initializeComputations('trip-1', [ComputationName::ROUTE]);
        $this->tracker->initializeComputations('trip-2', [
            ComputationName::ROUTE,
            ComputationName::STAGES,
        ]);

        $this->tracker->markDone('trip-1', ComputationName::ROUTE);
        $this->tracker->markFailed('trip-2', ComputationName::ROUTE);

        self::assertSame(
            ['completed' => 1, 'failed' => 0, 'total' => 1],
            $this->tracker->getProgress('trip-1'),
        );
        self::assertSame(
            ['completed' => 0, 'failed' => 1, 'total' => 2],
            $this->tracker->getProgress('trip-2'),
        );
    }

    #[Test]
    public function claimReadyPublicationReturnsTrueOnFirstCall(): void
    {
        self::assertTrue($this->tracker->claimReadyPublication('trip-1'));
    }

    #[Test]
    public function claimReadyPublicationReturnsFalseOnSubsequentCalls(): void
    {
        $this->tracker->claimReadyPublication('trip-1');

        self::assertFalse($this->tracker->claimReadyPublication('trip-1'));
    }
}
