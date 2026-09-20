<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Enum\AlertGroup;
use App\Repository\LockingTripRequestRepository;
use App\Repository\MergesGroupWritesAtomically;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The exemption from the per-trip lock belongs to the storage engine, not to the decorator.
 *
 * ADR-068 skips the lock on enrichment writes because a `jsonb_set` UPDATE merges in the
 * database, so a dozen producers finishing at once all survive. That is a property of the
 * Doctrine implementation. An implementation that reads the whole stage collection, mutates
 * it and writes it back still needs the lock, or a write lands between another's read and
 * write and is silently reverted — recette #649, which the lock exists to close.
 *
 * Each case probes the lock from *inside* the write, the way a second process would.
 */
final class LockingGroupWriteTest extends TestCase
{
    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009a1';

    private const string STAGE_ID = '01936f6e-0000-7000-8000-0000000009a2';

    private LockFactory $lockFactory;

    private ?bool $observed = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    #[Test]
    public function aBlobBackedImplementationKeepsTheLock(): void
    {
        $decorated = $this->createStub(TripRequestRepositoryInterface::class);
        $decorated->method('updateStageAlertsForGroup')->willReturnCallback(
            function (): void {
                $this->observed = $this->probe();
            },
        );

        $this->repository($decorated)->updateStageAlertsForGroup(
            self::TRIP_ID,
            self::STAGE_ID,
            AlertGroup::FERRY,
            [['code' => 'ferry_crossing', 'type' => 'warning', 'message' => 'Ferry']],
        );

        self::assertFalse($this->observed, 'A concurrent writer took the lock during a group write.');
    }

    #[Test]
    public function anImplementationThatMergesInPlaceGoesStraightThrough(): void
    {
        $decorated = $this->createStubForIntersectionOfInterfaces(
            [TripRequestRepositoryInterface::class, MergesGroupWritesAtomically::class],
        );
        $decorated->method('updateTripAlertsForGroup')->willReturnCallback(
            function (): void {
                $this->observed = $this->probe();
            },
        );
        \assert($decorated instanceof TripRequestRepositoryInterface);

        $this->repository($decorated)->updateTripAlertsForGroup(self::TRIP_ID, AlertGroup::CALENDAR, []);

        self::assertTrue($this->observed, 'The lock was taken anyway, serialising handlers that run in parallel by design.');
    }

    /** What another process would see: a separate lock instance on the same key. */
    private function probe(): bool
    {
        return $this->lockFactory
            ->createLock(\sprintf('trip.%s.stages.update', self::TRIP_ID), 5)
            ->acquire();
    }

    private function repository(TripRequestRepositoryInterface $decorated): LockingTripRequestRepository
    {
        return new LockingTripRequestRepository($decorated, $this->lockFactory);
    }
}
