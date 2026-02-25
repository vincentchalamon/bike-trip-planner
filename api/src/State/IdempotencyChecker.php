<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class IdempotencyChecker implements IdempotencyCheckerInterface
{
    private const int TTL = 1800; // 30 minutes

    public function __construct(
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
    ) {
    }

    public function hasChanged(string $tripId, TripRequest $newRequest): bool
    {
        $storedHash = $this->getHash($tripId);
        $newHash = $this->computeHash($newRequest);

        return $storedHash !== $newHash;
    }

    public function saveHash(string $tripId, TripRequest $request): void
    {
        $item = $this->tripStateCache->getItem($this->hashKey($tripId));
        $item->set($this->computeHash($request));
        $item->expiresAfter(self::TTL);

        $this->tripStateCache->save($item);
    }

    private function getHash(string $tripId): ?string
    {
        $item = $this->tripStateCache->getItem($this->hashKey($tripId));

        $value = $item->get();

        return $item->isHit() && \is_string($value) ? $value : null;
    }

    private function computeHash(TripRequest $request): string
    {
        return hash('xxh128', serialize([
            $request->sourceUrl,
            $request->startDate?->format('Y-m-d'),
            $request->endDate?->format('Y-m-d'),
            $request->fatigueFactor,
            $request->elevationPenalty,
        ]));
    }

    private function hashKey(string $tripId): string
    {
        return \sprintf('trip.%s.request_hash', $tripId);
    }
}
