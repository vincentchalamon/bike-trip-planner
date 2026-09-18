<?php

declare(strict_types=1);

namespace App\Tests\Unit\ComputationTracker;

use App\ComputationTracker\TripGenerationTracker;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The generation is the trip's persisted structural version, so this only pins the
 * delegation. What the generation must actually guarantee — that it is bumped by every
 * write of the stage collection, never expires and never decreases — is a property of the
 * stored column, covered by
 * {@see \App\Tests\Integration\Repository\DoctrineStageReconciliationTest}.
 */
final class TripGenerationTrackerTest extends TestCase
{
    #[Test]
    public function theCurrentGenerationIsTheTripVersion(): void
    {
        $repository = $this->createMock(TripRequestRepositoryInterface::class);
        $repository->expects(self::once())->method('getVersion')->with('trip-1')->willReturn(7);

        self::assertSame(7, new TripGenerationTracker($repository)->current('trip-1'));
    }

    #[Test]
    public function incrementingBumpsTheTripVersion(): void
    {
        $repository = $this->createMock(TripRequestRepositoryInterface::class);
        $repository->expects(self::once())->method('bumpVersion')->with('trip-1')->willReturn(8);

        self::assertSame(8, new TripGenerationTracker($repository)->increment('trip-1'));
    }

    #[Test]
    public function anUnknownTripHasNoGeneration(): void
    {
        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $repository->method('getVersion')->willReturn(null);

        self::assertNull(new TripGenerationTracker($repository)->current('unknown'));
    }

    /** A trip row exists at version 1, so there is nothing to seed. */
    #[Test]
    public function initializingWritesNothing(): void
    {
        $repository = $this->createMock(TripRequestRepositoryInterface::class);
        $repository->expects(self::never())->method('bumpVersion');

        new TripGenerationTracker($repository)->initialize('trip-1');
    }
}
