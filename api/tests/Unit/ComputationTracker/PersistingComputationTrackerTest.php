<?php

declare(strict_types=1);

namespace App\Tests\Unit\ComputationTracker;

use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\PersistingComputationTracker;
use App\Enum\ComputationName;
use App\ComputationTracker\ComputationStatusStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The state has to outlive the cache that holds it (ADR-072).
 *
 * The tracked map lived only in Redis under a 30-minute TTL — shorter than the life of a
 * trip. Past it the state ceased to exist anywhere, and both read paths fell back to "there
 * are stages, so it must be analysed": a trip whose every computation had failed reported
 * success.
 */
final class PersistingComputationTrackerTest extends TestCase
{
    /**
     * The point of the class, in one assertion.
     */
    #[Test]
    public function aStatusMapThatFellOutOfTheCacheIsStillAnswered(): void
    {
        $inner = $this->createStub(ComputationTrackerInterface::class);
        $inner->method('getStatuses')->willReturn(null);

        $trips = $this->createStub(ComputationStatusStore::class);
        $trips->method('getComputationStatus')->willReturn(['route' => 'done', 'weather' => 'failed']);

        $tracker = new PersistingComputationTracker($inner, $trips);

        self::assertSame(['route' => 'done', 'weather' => 'failed'], $tracker->getStatuses('trip-1'));
    }

    /**
     * An unknown trip and one whose computations are all still pending must not read alike:
     * the first has nothing to say, the second is mid-flight.
     */
    #[Test]
    public function anEmptyMirrorReadsAsNothingTracked(): void
    {
        $inner = $this->createStub(ComputationTrackerInterface::class);
        $inner->method('getStatuses')->willReturn(null);

        $trips = $this->createStub(ComputationStatusStore::class);
        $trips->method('getComputationStatus')->willReturn([]);

        self::assertNull(new PersistingComputationTracker($inner, $trips)->getStatuses('trip-1'));
    }

    /**
     * The cache stays authoritative while it has an answer — the mirror is a fallback, not a
     * second source of truth to reconcile.
     */
    #[Test]
    public function theCacheWinsWhileItStillHasTheMap(): void
    {
        $inner = $this->createStub(ComputationTrackerInterface::class);
        $inner->method('getStatuses')->willReturn(['route' => 'running']);

        $trips = $this->createMock(ComputationStatusStore::class);
        $trips->expects($this->never())->method('getComputationStatus');

        self::assertSame(['route' => 'running'], new PersistingComputationTracker($inner, $trips)->getStatuses('trip-1'));
    }

    /**
     * Only transitions are mirrored: while a computation runs the cache is alive by
     * construction, and the entry a `running` would write is the one initialization put there.
     */
    #[Test]
    public function runningIsNotMirroredButSettlingIs(): void
    {
        $inner = $this->createStub(ComputationTrackerInterface::class);
        $inner->method('getStatuses')->willReturn(['terrain' => 'done', 'weather' => 'failed']);

        $trips = $this->createMock(ComputationStatusStore::class);
        $trips->expects($this->exactly(2))->method('mergeComputationStatus');

        $tracker = new PersistingComputationTracker($inner, $trips);

        $tracker->markRunning('trip-1', ComputationName::TERRAIN);
        $tracker->markDone('trip-1', ComputationName::TERRAIN);
        $tracker->markFailed('trip-1', ComputationName::WEATHER);
    }

    /**
     * The one entry that moved, never the whole map: the tracker's lock ends at its own Redis
     * write, so two workers settling at once could otherwise land their read-then-overwrite in
     * the opposite order and erase each other — silently, since the mirror is only read once
     * the cache is gone.
     */
    #[Test]
    public function aSettledComputationIsMirroredOnItsOwn(): void
    {
        $inner = $this->createStub(ComputationTrackerInterface::class);
        $inner->method('getStatuses')->willReturn(['route' => 'done', 'terrain' => 'failed']);

        $trips = $this->createMock(ComputationStatusStore::class);
        $trips->expects($this->once())
            ->method('mergeComputationStatus')
            ->with('trip-1', 'terrain', 'failed');
        $trips->expects($this->never())->method('replaceComputationStatus');

        new PersistingComputationTracker($inner, $trips)->markFailed('trip-1', ComputationName::TERRAIN);
    }

    /**
     * The exception: a generation starting over replaces the map wholesale, so the column does
     * not keep answering with the previous generation's verdicts.
     */
    #[Test]
    public function initializationReplacesTheWholeMap(): void
    {
        $inner = $this->createStub(ComputationTrackerInterface::class);
        $inner->method('getStatuses')->willReturn(['route' => 'pending', 'terrain' => 'pending']);

        $trips = $this->createMock(ComputationStatusStore::class);
        $trips->expects($this->once())
            ->method('replaceComputationStatus')
            ->with('trip-1', ['route' => 'pending', 'terrain' => 'pending']);

        new PersistingComputationTracker($inner, $trips)
            ->initializeComputations('trip-1', [ComputationName::ROUTE, ComputationName::TERRAIN]);
    }

    /**
     * A reset is not a settling, but it undoes one: a column left claiming `done` for work
     * about to be redone would outlive the cache saying otherwise.
     */
    #[Test]
    public function aResetIsMirroredToo(): void
    {
        $inner = $this->createStub(ComputationTrackerInterface::class);
        $inner->method('getStatuses')->willReturn(['terrain' => 'pending']);

        $trips = $this->createMock(ComputationStatusStore::class);
        $trips->expects($this->once())
            ->method('mergeComputationStatus')
            ->with('trip-1', 'terrain', 'pending');

        new PersistingComputationTracker($inner, $trips)->resetComputation('trip-1', ComputationName::TERRAIN);
    }

    /**
     * The list page reads many trips at once, so the fallback has to work in batch too —
     * otherwise every trip older than half an hour drops back to guessing from stage count.
     */
    #[Test]
    public function theBatchReadFallsBackOnlyForTheTripsTheCacheLost(): void
    {
        $inner = $this->createStub(ComputationTrackerInterface::class);
        $inner->method('getStatusesBatch')->willReturn([
            'hot' => ['route' => 'running'],
            'cold' => null,
        ]);

        $trips = $this->createMock(ComputationStatusStore::class);
        $trips->expects($this->once())
            ->method('getComputationStatusBatch')
            ->with(['cold'])
            ->willReturn(['cold' => ['route' => 'failed']]);

        $statuses = new PersistingComputationTracker($inner, $trips)->getStatusesBatch(['hot', 'cold']);

        self::assertSame(['route' => 'running'], $statuses['hot']);
        self::assertSame(['route' => 'failed'], $statuses['cold']);
    }
}
