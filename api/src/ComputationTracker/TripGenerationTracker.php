<?php

declare(strict_types=1);

namespace App\ComputationTracker;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TripGenerationTracker implements TripGenerationTrackerInterface
{
    private const int TTL = 1800; // 30 minutes — same as trip state

    public function __construct(
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
    ) {
    }

    public function initialize(string $tripId): void
    {
        $this->set($tripId, 1);
    }

    public function increment(string $tripId): int
    {
        $item = $this->tripStateCache->getItem($this->key($tripId));
        /** @var int|null $cached */
        $cached = $item->get();
        $current = $item->isHit() ? $cached : 0;
        $next = $current + 1;
        $item->set($next);
        $item->expiresAfter(self::TTL);

        $this->tripStateCache->save($item);

        return $next;
    }

    public function current(string $tripId): ?int
    {
        $item = $this->tripStateCache->getItem($this->key($tripId));

        if (!$item->isHit()) {
            return null;
        }

        /** @var int $value */
        $value = $item->get();

        return $value;
    }

    private function set(string $tripId, int $generation): void
    {
        $item = $this->tripStateCache->getItem($this->key($tripId));
        $item->set($generation);
        $item->expiresAfter(self::TTL);

        $this->tripStateCache->save($item);
    }

    private function key(string $tripId): string
    {
        return \sprintf('trip.%s.generation', $tripId);
    }
}
