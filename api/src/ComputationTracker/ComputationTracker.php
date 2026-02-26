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

    /** @return array<string, string>|null */
    public function getStatuses(string $tripId): ?array
    {
        /** @var array<string, string>|null $value */
        $value = $this->get($this->statusKey($tripId));

        return $value;
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
}
