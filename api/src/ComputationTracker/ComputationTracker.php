<?php

declare(strict_types=1);

namespace App\ComputationTracker;

use App\Enum\ComputationName;
use App\Enum\ComputationStatus;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

final readonly class ComputationTracker implements ComputationTrackerInterface
{
    private const int TTL = 1800; // 30 minutes

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
            $statuses[$computation->value] = ComputationStatus::PENDING->value;
        }

        $this->set($this->statusKey($tripId), $statuses);
    }

    public function markRunning(string $tripId, ComputationName $computation): void
    {
        $this->updateStatus($tripId, $computation, ComputationStatus::RUNNING->value);
    }

    public function markDone(string $tripId, ComputationName $computation): void
    {
        $this->updateStatus($tripId, $computation, ComputationStatus::DONE->value);
    }

    public function markFailed(string $tripId, ComputationName $computation): void
    {
        $this->updateStatus($tripId, $computation, ComputationStatus::FAILED->value);
    }

    /**
     * Compare-and-set, under the same lock as every other status write: the caller runs after
     * a generation bump, and a worker of the newer generation may already have settled this
     * computation. Writing over a `done` would report abandoned work that in fact succeeded.
     */
    public function markSupersededUnlessSettled(string $tripId, ComputationName $computation): bool
    {
        return $this->withStatusLock($tripId, function () use ($tripId, $computation): bool {
            $statuses = $this->getStatuses($tripId) ?? [];

            if (!$this->isInFlight($statuses[$computation->value] ?? null)) {
                return false;
            }

            $statuses[$computation->value] = ComputationStatus::SUPERSEDED->value;
            $this->set($this->statusKey($tripId), $statuses);

            return true;
        });
    }

    /**
     * The dual, and cheap on the common path: a computation that is already `pending` or
     * `running` is read and left alone, without taking the lock or writing.
     *
     * That first read only decides whether the lock is worth taking. Another worker may start
     * the computation while this one waits for the lock, so the decision is made again under
     * it; rearming on the stale read would turn that worker's `running` back into `pending`.
     */
    public function rearmIfSettled(string $tripId, ComputationName $computation): bool
    {
        $current = ($this->getStatuses($tripId) ?? [])[$computation->value] ?? null;
        if (null === $current || $this->isInFlight($current)) {
            return false;
        }

        return $this->withStatusLock($tripId, function () use ($tripId, $computation): bool {
            $statuses = $this->getStatuses($tripId) ?? [];
            $current = $statuses[$computation->value] ?? null;
            if (null === $current || $this->isInFlight($current)) {
                return false;
            }

            $statuses[$computation->value] = ComputationStatus::PENDING->value;
            $this->set($this->statusKey($tripId), $statuses);

            return true;
        });
    }

    public function resetComputation(string $tripId, ComputationName $computation): void
    {
        $this->updateStatus($tripId, $computation, ComputationStatus::PENDING->value);
    }

    public function claimReadyPublication(string $tripId, ?int $generation = null): bool
    {
        // Check and set under the lock: two workers settling the last computations at once
        // would otherwise both read "unclaimed" and both publish `trip_ready` (#303).
        return $this->withStatusLock($tripId, function () use ($tripId, $generation): bool {
            $item = $this->tripStateCache->getItem($this->readyClaimedKey($tripId, $generation));
            if ($item->isHit()) {
                return false;
            }

            $item->set(true);
            $item->expiresAfter(self::TTL);

            $this->tripStateCache->save($item);

            return true;
        });
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
            $parsed = ComputationStatus::tryFrom($status);
            if (null === $parsed || !$parsed->isSettled()) {
                continue;
            }

            // `superseded` counts here and nowhere else: terminal, so it closes the gate, but
            // neither a success nor a failure, so it is nothing the progress bar renders
            // (ADR-073).
            ++$settled;

            if (ComputationStatus::DONE === $parsed) {
                ++$completed;
            } elseif (ComputationStatus::FAILED === $parsed) {
                ++$failed;
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
        $this->withStatusLock($tripId, function () use ($tripId, $computation, $status): void {
            $statuses = $this->getStatuses($tripId) ?? [];
            $statuses[$computation->value] = $status;
            $this->set($this->statusKey($tripId), $statuses);
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $critical
     *
     * @return T
     */
    private function withStatusLock(string $tripId, callable $critical): mixed
    {
        $lock = $this->lockFactory->createLock(\sprintf('trip.%s.computation_status.update', $tripId), ttl: 5);
        $lock->acquire(blocking: true);

        try {
            return $critical();
        } finally {
            $lock->release();
        }
    }

    private function isInFlight(?string $status): bool
    {
        return ComputationStatus::PENDING->value === $status || ComputationStatus::RUNNING->value === $status;
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
