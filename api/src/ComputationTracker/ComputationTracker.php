<?php

declare(strict_types=1);

namespace App\ComputationTracker;

use App\Enum\ComputationName;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ComputationTracker implements ComputationTrackerInterface
{
    private const int TTL = 1800; // 30 minutes

    private const string PENDING = 'pending';

    private const string RUNNING = 'running';

    private const string DONE = 'done';

    private const string FAILED = 'failed';

    public function __construct(
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
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

    public function resetComputation(string $tripId, ComputationName $computation): void
    {
        $this->updateStatus($tripId, $computation, self::PENDING);
    }

    public function isAllComplete(string $tripId): bool
    {
        $statuses = $this->getStatuses($tripId);
        if (null === $statuses) {
            return false;
        }

        return array_all($statuses, fn ($status): bool => !(self::DONE !== $status && self::FAILED !== $status));
    }

    public function claimReadyPublication(string $tripId): bool
    {
        $item = $this->tripStateCache->getItem($this->readyClaimedKey($tripId));
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
            return ['completed' => 0, 'failed' => 0, 'total' => 0];
        }

        $completed = 0;
        $failed = 0;
        foreach ($statuses as $status) {
            if (self::DONE === $status) {
                ++$completed;
            } elseif (self::FAILED === $status) {
                ++$failed;
            }
        }

        return [
            'completed' => $completed,
            'failed' => $failed,
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

    private function updateStatus(string $tripId, ComputationName $computation, string $status): void
    {
        $statuses = $this->getStatuses($tripId) ?? [];
        $statuses[$computation->value] = $status;
        $this->set($this->statusKey($tripId), $statuses);
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

    private function readyClaimedKey(string $tripId): string
    {
        return \sprintf('trip.%s.ready_claimed', $tripId);
    }
}
