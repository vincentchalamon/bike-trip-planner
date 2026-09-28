<?php

declare(strict_types=1);

namespace App\Repository;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(TransientTripPointsStoreInterface::class)]
final readonly class TransientTripPointsStore implements TransientTripPointsStoreInterface
{
    private const int CACHE_TTL = 1800; // 30 minutes for transient data

    public function __construct(
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
    ) {
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $rawPoints */
    public function storeRawPoints(string $tripId, array $rawPoints): void
    {
        $this->cacheSet(\sprintf('trip.%s.raw_points', $tripId), $rawPoints);
    }

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getRawPoints(string $tripId): ?array
    {
        /** @var list<array{lat: float, lon: float, ele: float}>|null $value */
        $value = $this->cacheGet(\sprintf('trip.%s.raw_points', $tripId));

        return $value;
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $decimatedPoints */
    public function storeDecimatedPoints(string $tripId, array $decimatedPoints): void
    {
        $this->cacheSet(\sprintf('trip.%s.decimated_points', $tripId), $decimatedPoints);
    }

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getDecimatedPoints(string $tripId): ?array
    {
        /** @var list<array{lat: float, lon: float, ele: float}>|null $value */
        $value = $this->cacheGet(\sprintf('trip.%s.decimated_points', $tripId));

        return $value;
    }

    /**
     * @param list<list<array{lat: float, lon: float, ele: float}>> $tracksData
     */
    public function storeTracksData(string $tripId, array $tracksData): void
    {
        $this->cacheSet(\sprintf('trip.%s.tracks_data', $tripId), $tracksData);
    }

    /** @return list<list<array{lat: float, lon: float, ele: float}>>|null */
    public function getTracksData(string $tripId): ?array
    {
        /** @var list<list<array{lat: float, lon: float, ele: float}>>|null $value */
        $value = $this->cacheGet(\sprintf('trip.%s.tracks_data', $tripId));

        return $value;
    }

    private function cacheSet(string $key, mixed $value): void
    {
        $item = $this->tripStateCache->getItem($key);
        $item->set($value);
        $item->expiresAfter(self::CACHE_TTL);

        $this->tripStateCache->save($item);
    }

    private function cacheGet(string $key): mixed
    {
        $item = $this->tripStateCache->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        return $item->get();
    }
}
