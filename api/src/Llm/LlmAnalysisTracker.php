<?php

declare(strict_types=1);

namespace App\Llm;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * PSR-6 backed implementation of {@see LlmAnalysisTrackerInterface}.
 *
 * Stores three keys per trip in the `cache.trip_state` (Redis) pool:
 *  - `trip.{id}.llm_progress` → `{completed, failed, total}`
 *  - `trip.{id}.llm_overview_claimed` → boolean
 *  - `trip.{id}.llm_ready_claimed` → boolean
 *
 * The TOCTOU window of the read-modify-write counter is sub-millisecond and
 * acceptable for a non-business-critical progress signal: if two workers
 * settle simultaneously and one increment is lost, the only consequence is a
 * progress-bar tick missing from the wire — pass-2 still runs (claim is NX)
 * and TRIP_READY still fires.
 */
final readonly class LlmAnalysisTracker implements LlmAnalysisTrackerInterface
{
    private const int TTL = 1800; // 30 minutes — same horizon as ComputationTracker

    public function __construct(
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
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

    private function claim(string $key): bool
    {
        $item = $this->tripStateCache->getItem($key);
        if ($item->isHit()) {
            return false;
        }

        $item->set(true);
        $item->expiresAfter(self::TTL);

        $this->tripStateCache->save($item);

        return true;
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
}
