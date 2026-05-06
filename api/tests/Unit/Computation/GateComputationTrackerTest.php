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
 * - {@see ComputationTracker::areAllEnrichmentsCompleted()} returns true once
 *   every initialized computation reached a terminal status (`done` or `failed`).
 * - {@see ComputationTracker::getProgress()} returns up-to-date counters that
 *   power the `computation_step_completed` Mercure event.
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
    public function areAllEnrichmentsCompletedIsFalseWhenTripIsUnknown(): void
    {
        self::assertFalse($this->tracker->areAllEnrichmentsCompleted('unknown-trip'));
    }

    #[Test]
    public function areAllEnrichmentsCompletedIsFalseWhilePending(): void
    {
        $this->tracker->initializeComputations('trip-1', [
            ComputationName::ROUTE,
            ComputationName::STAGES,
        ]);

        $this->tracker->markDone('trip-1', ComputationName::ROUTE);

        self::assertFalse($this->tracker->areAllEnrichmentsCompleted('trip-1'));
    }

    #[Test]
    public function areAllEnrichmentsCompletedIsFalseWhileRunning(): void
    {
        $this->tracker->initializeComputations('trip-1', [
            ComputationName::ROUTE,
            ComputationName::STAGES,
        ]);

        $this->tracker->markDone('trip-1', ComputationName::ROUTE);
        $this->tracker->markRunning('trip-1', ComputationName::STAGES);

        self::assertFalse($this->tracker->areAllEnrichmentsCompleted('trip-1'));
    }

    #[Test]
    public function areAllEnrichmentsCompletedIsTrueWhenEveryStepIsDone(): void
    {
        $this->tracker->initializeComputations('trip-1', [
            ComputationName::ROUTE,
            ComputationName::STAGES,
            ComputationName::WEATHER,
        ]);

        $this->tracker->markDone('trip-1', ComputationName::ROUTE);
        $this->tracker->markDone('trip-1', ComputationName::STAGES);
        $this->tracker->markDone('trip-1', ComputationName::WEATHER);

        self::assertTrue($this->tracker->areAllEnrichmentsCompleted('trip-1'));
    }

    #[Test]
    public function failedStepsCountAsCompletedForTheGate(): void
    {
        $this->tracker->initializeComputations('trip-1', [
            ComputationName::ROUTE,
            ComputationName::STAGES,
        ]);

        $this->tracker->markDone('trip-1', ComputationName::ROUTE);
        $this->tracker->markFailed('trip-1', ComputationName::STAGES);

        self::assertTrue(
            $this->tracker->areAllEnrichmentsCompleted('trip-1'),
            'Failed enrichments must satisfy the gate so a single failure does not stall the pipeline.',
        );
    }

    #[Test]
    public function gateRemainsOpenWhenAllStepsFailed(): void
    {
        $this->tracker->initializeComputations('trip-1', [
            ComputationName::ROUTE,
            ComputationName::STAGES,
        ]);

        $this->tracker->markFailed('trip-1', ComputationName::ROUTE);
        $this->tracker->markFailed('trip-1', ComputationName::STAGES);

        self::assertTrue($this->tracker->areAllEnrichmentsCompleted('trip-1'));
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
    public function gateIsConsistentWithLegacyIsAllCompleteApi(): void
    {
        $this->tracker->initializeComputations('trip-1', [
            ComputationName::ROUTE,
            ComputationName::STAGES,
        ]);

        $this->tracker->markDone('trip-1', ComputationName::ROUTE);
        $this->tracker->markFailed('trip-1', ComputationName::STAGES);

        self::assertSame(
            $this->tracker->isAllComplete('trip-1'),
            $this->tracker->areAllEnrichmentsCompleted('trip-1'),
            'areAllEnrichmentsCompleted() must mirror the existing isAllComplete() contract so callers can migrate safely.',
        );
    }
}
