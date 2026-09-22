<?php

declare(strict_types=1);

namespace App\ComputationTracker;

use App\Enum\ComputationName;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

final readonly class ComputationTracker implements ComputationTrackerInterface
{
    private const int TTL = 1800; // 30 minutes

    private const string PENDING = 'pending';

    private const string RUNNING = 'running';

    private const string DONE = 'done';

    private const string FAILED = 'failed';

    /** Terminal, and not a failure: the trip moved past this computation before it settled. */
    private const string SUPERSEDED = 'superseded';

    public function __construct(
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
        private LockFactory $lockFactory,
    ) {
    }

    /** @param list<ComputationName> $computations */
    public function initializeComputations(string $tripId, array $computations): void
    {
        $statuses = [];
        foreach ($computations as $computation) {
            $statuses[$computation->value] = self::PENDING;
        }

        $this->set($this->statusKey($tripId), $statuses);
    }

    public function markRunning(string $tripId, ComputationName $computation): void
    {
        $this->updateStatus($tripId, $computation, self::RUNNING);
    }

    public function markDone(string $tripId, ComputationName $computation): void
    {
        $this->updateStatus($tripId, $computation, self::DONE);
    }

    public function markFailed(string $tripId, ComputationName $computation): void
    {
        $this->updateStatus($tripId, $computation, self::FAILED);
    }

    /**
     * Compare-and-set, under the same lock as every other status write: the caller runs after
     * a generation bump, and a worker of the newer generation may already have settled this
     * computation. Writing over a `done` would report abandoned work that in fact succeeded.
     */
    public function markSupersededUnlessSettled(string $tripId, ComputationName $computation): bool
    {
        $lock = $this->lockFactory->createLock(\sprintf('trip.%s.computation_status.update', $tripId), ttl: 5);
        $lock->acquire(blocking: true);

        try {
            $statuses = $this->getStatuses($tripId) ?? [];
            $current = $statuses[$computation->value] ?? null;

            if (self::PENDING !== $current && self::RUNNING !== $current) {
                return false;
            }

            $statuses[$computation->value] = self::SUPERSEDED;
            $this->set($this->statusKey($tripId), $statuses);

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * The dual, and cheap on the common path: a computation that is already `pending` or
     * `running` is read and left alone, without taking the lock or writing.
     */
    public function rearmIfSettled(string $tripId, ComputationName $computation): bool
    {
        $current = ($this->getStatuses($tripId) ?? [])[$computation->value] ?? null;
        if (null === $current || self::PENDING === $current || self::RUNNING === $current) {
            return false;
        }

        $lock = $this->lockFactory->createLock(\sprintf('trip.%s.computation_status.update', $tripId), ttl: 5);
        $lock->acquire(blocking: true);

        try {
            $statuses = $this->getStatuses($tripId) ?? [];
            if (!isset($statuses[$computation->value])) {
                return false;
            }

            $statuses[$computation->value] = self::PENDING;
            $this->set($this->statusKey($tripId), $statuses);

            return true;
        } finally {
            $lock->release();
        }
    }

    public function resetComputation(string $tripId, ComputationName $computation): void
    {
        $this->updateStatus($tripId, $computation, self::PENDING);
    }

    public function claimReadyPublication(string $tripId, ?int $generation = null): bool
    {
        $item = $this->tripStateCache->getItem($this->readyClaimedKey($tripId, $generation));
        if ($item->isHit()) {
            return false;
        }

        $item->set(true);
        $item->expiresAfter(self::TTL);

        $this->tripStateCache->save($item);

        return true;
    }

    public function getProgress(string $tripId): array
    {
        $statuses = $this->getStatuses($tripId);
        if (null === $statuses) {
            return ['completed' => 0, 'failed' => 0, 'settled' => 0, 'total' => 0];
        }

        $completed = 0;
        $failed = 0;
        $settled = 0;
        foreach ($statuses as $status) {
            if (self::DONE === $status) {
                ++$completed;
                ++$settled;
            } elseif (self::FAILED === $status) {
                ++$failed;
                ++$settled;
            } elseif (self::SUPERSEDED === $status) {
                // Terminal, but neither a success nor a failure: it counts towards the gate
                // and towards nothing the progress bar renders (ADR-073).
                ++$settled;
            }
        }

        return [
            'completed' => $completed,
            'failed' => $failed,
            'settled' => $settled,
            'total' => \count($statuses),
        ];
    }

    /** @return array<string, string>|null */
    public function getStatuses(string $tripId): ?array
    {
        /** @var array<string, string>|null $value */
        $value = $this->get($this->statusKey($tripId));

        return $value;
    }

    public function getStatusesBatch(array $tripIds): array
    {
        if ([] === $tripIds) {
            return [];
        }

        $keysByTripId = [];
        foreach ($tripIds as $tripId) {
            $keysByTripId[$tripId] = $this->statusKey($tripId);
        }

        $itemsByKey = [];
        foreach ($this->tripStateCache->getItems(array_values($keysByTripId)) as $key => $item) {
            $itemsByKey[$key] = $item;
        }

        $result = [];
        foreach ($keysByTripId as $tripId => $key) {
            $item = $itemsByKey[$key] ?? null;
            if (null === $item || !$item->isHit()) {
                $result[$tripId] = null;
                continue;
            }

            /** @var array<string, string>|null $value */
            $value = $item->get();
            $result[$tripId] = $value;
        }

        return $result;
    }

    /**
     * The whole status map lives under one Redis key, so a naive get-modify-set
     * lets two parallel enrichment workers clobber each other's write — a lost
     * `done` reverts a computation to `running` for good, and the completion gate
     * never fires (loader spins forever on a computed trip). Serialise the
     * read-modify-write behind a per-trip lock.
     */
    private function updateStatus(string $tripId, ComputationName $computation, string $status): void
    {
        $lock = $this->lockFactory->createLock(\sprintf('trip.%s.computation_status.update', $tripId), ttl: 5);
        $lock->acquire(blocking: true);

        try {
            $statuses = $this->getStatuses($tripId) ?? [];
            $statuses[$computation->value] = $status;
            $this->set($this->statusKey($tripId), $statuses);
        } finally {
            $lock->release();
        }
    }

    private function set(string $key, mixed $value): void
    {
        $item = $this->tripStateCache->getItem($key);
        $item->set($value);
        $item->expiresAfter(self::TTL);

        $this->tripStateCache->save($item);
    }

    private function get(string $key): mixed
    {
        $item = $this->tripStateCache->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    private function statusKey(string $tripId): string
    {
        return \sprintf('trip.%s.computation_status', $tripId);
    }

    /**
     * Scoped to the generation that settled.
     *
     * It used to be one key per trip, with a 30-minute TTL and nothing ever clearing it — not
     * `initializeComputations()`, not `resetComputation()`. So the first generation to publish
     * `trip_ready` claimed the slot for every generation after it, and an edited trip never
     * announced that it was ready again (ADR-073).
     */
    private function readyClaimedKey(string $tripId, ?int $generation): string
    {
        return \sprintf('trip.%s.ready_claimed.%s', $tripId, $generation ?? 'none');
    }
}
