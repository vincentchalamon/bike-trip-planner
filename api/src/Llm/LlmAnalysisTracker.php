<?php

declare(strict_types=1);

namespace App\Llm;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * PSR-6 backed implementation of {@see LlmAnalysisTrackerInterface}.
 *
 * Stores three keys per trip in the `cache.trip_state` (Redis) pool:
 *  - `trip.{id}.llm_progress` → `{completed, failed, total}`
 *  - `trip.{id}.llm_overview_claimed` → boolean
 *  - `trip.{id}.llm_ready_claimed` → boolean
 *
 * Concurrency guarantees:
 *  - The progress counter read-modify-write is serialized through a Symfony Lock
 *    so two parallel workers settling at the same instant cannot lose updates
 *    (which would freeze the pipeline if the counter never reaches `total`).
 *  - The two claim slots use Symfony Lock with `autoRelease: false` so the slot
 *    stays held for the lifetime of the TTL — true NX semantics, no double-claim.
 */
final readonly class LlmAnalysisTracker implements LlmAnalysisTrackerInterface
{
    private const int TTL = 1800; // 30 minutes — same horizon as ComputationTracker

    public function __construct(
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
        private LockFactory $lockFactory,
    ) {
    }

    public function initializeStageAnalyses(string $tripId, int $expectedStages): void
    {
        $this->set($this->progressKey($tripId), [
            'completed' => 0,
            'failed' => 0,
            'total' => max(0, $expectedStages),
        ]);
    }

    public function markStageAnalysisSettled(string $tripId, bool $success): array
    {
        $lock = $this->lockFactory->createLock(\sprintf('trip.%s.llm_progress.update', $tripId), ttl: 5);
        $lock->acquire(blocking: true);

        try {
            $progress = $this->getStageAnalysisProgress($tripId) ?? [
                'completed' => 0,
                'failed' => 0,
                'total' => 0,
            ];

            if ($success) {
                ++$progress['completed'];
            } else {
                ++$progress['failed'];
            }

            $this->set($this->progressKey($tripId), $progress);

            return $progress;
        } finally {
            $lock->release();
        }
    }

    public function getStageAnalysisProgress(string $tripId): ?array
    {
        $item = $this->tripStateCache->getItem($this->progressKey($tripId));
        if (!$item->isHit()) {
            return null;
        }

        /** @var array{completed: int, failed: int, total: int}|null $value */
        $value = $item->get();

        return $value;
    }

    public function claimOverviewDispatch(string $tripId): bool
    {
        return $this->claim($this->overviewClaimKey($tripId));
    }

    public function claimTripReadyPublication(string $tripId): bool
    {
        return $this->claim($this->readyClaimKey($tripId));
    }

    public function markSkipAiAnalysis(string $tripId): void
    {
        $item = $this->tripStateCache->getItem($this->skipAiAnalysisKey($tripId));
        $item->set(true);
        $item->expiresAfter(self::TTL);

        $this->tripStateCache->save($item);
    }

    public function consumeSkipAiAnalysis(string $tripId): bool
    {
        $key = $this->skipAiAnalysisKey($tripId);
        $item = $this->tripStateCache->getItem($key);
        if (!$item->isHit()) {
            return false;
        }

        $this->tripStateCache->deleteItem($key);

        return true === $item->get();
    }

    /**
     * Atomic NX-claim via Symfony Lock with autoRelease disabled — the lock survives
     * the request and acts as a single-use slot for the TTL lifetime.
     */
    private function claim(string $key): bool
    {
        $lock = $this->lockFactory->createLock($key, ttl: self::TTL, autoRelease: false);

        return $lock->acquire(blocking: false);
    }

    /** @param array{completed: int, failed: int, total: int} $value */
    private function set(string $key, array $value): void
    {
        $item = $this->tripStateCache->getItem($key);
        $item->set($value);
        $item->expiresAfter(self::TTL);

        $this->tripStateCache->save($item);
    }

    private function progressKey(string $tripId): string
    {
        return \sprintf('trip.%s.llm_progress', $tripId);
    }

    private function overviewClaimKey(string $tripId): string
    {
        return \sprintf('trip.%s.llm_overview_claimed', $tripId);
    }

    private function readyClaimKey(string $tripId): string
    {
        return \sprintf('trip.%s.llm_ready_claimed', $tripId);
    }

    private function skipAiAnalysisKey(string $tripId): string
    {
        return \sprintf('trip.%s.llm_skip_analysis', $tripId);
    }
}
