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
     * Only terminal transitions are mirrored: while a computation runs the cache is alive by
     * construction, and a write per intermediate step would buy nothing.
     */
    #[Test]
    public function runningIsNotMirroredButSettlingIs(): void
    {
        $inner = $this->createStub(ComputationTrackerInterface::class);
        $inner->method('getStatuses')->willReturn(['terrain' => 'done']);

        $written = [];
        $trips = $this->createStub(ComputationStatusStore::class);
        $trips->method('storeComputationStatus')->willReturnCallback(
            static function (string $tripId, array $statuses) use (&$written): void {
                $written[] = $statuses;
            },
        );

        $tracker = new PersistingComputationTracker($inner, $trips);

        $tracker->markRunning('trip-1', ComputationName::TERRAIN);
        self::assertCount(0, $written, 'A running computation keeps the cache alive on its own.');

        $tracker->markDone('trip-1', ComputationName::TERRAIN);
        $tracker->markFailed('trip-1', ComputationName::WEATHER);
        self::assertCount(2, $written);

        // The whole map, not the one entry that moved: five workers settle in no fixed order.
        self::assertSame(['terrain' => 'done'], $written[0]);
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
