<?php

declare(strict_types=1);

namespace App\Repository;

use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TripRequestRepository implements TripRequestRepositoryInterface
{
    private const int TTL = 1800; // 30 minutes

    public function __construct(
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
    ) {
    }

    public function initializeTrip(string $tripId, TripRequest $request): void
    {
        $this->set($this->requestKey($tripId), $request);
    }

    public function getRequest(string $tripId): ?TripRequest
    {
        /** @var TripRequest|null $value */
        $value = $this->get($this->requestKey($tripId));

        return $value;
    }

    public function storeRequest(string $tripId, TripRequest $request): void
    {
        $this->set($this->requestKey($tripId), $request);
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $rawPoints */
    public function storeRawPoints(string $tripId, array $rawPoints): void
    {
        $this->set($this->rawPointsKey($tripId), $rawPoints);
    }

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getRawPoints(string $tripId): ?array
    {
        /** @var list<array{lat: float, lon: float, ele: float}>|null $value */
        $value = $this->get($this->rawPointsKey($tripId));

        return $value;
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $decimatedPoints */
    public function storeDecimatedPoints(string $tripId, array $decimatedPoints): void
    {
        $this->set($this->decimatedPointsKey($tripId), $decimatedPoints);
    }

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getDecimatedPoints(string $tripId): ?array
    {
        /** @var list<array{lat: float, lon: float, ele: float}>|null $value */
        $value = $this->get($this->decimatedPointsKey($tripId));

        return $value;
    }

    /** @param list<Stage> $stages */
    public function storeStages(string $tripId, array $stages): void
    {
        $this->set($this->stagesKey($tripId), $stages);
    }

    /** @return list<Stage>|null */
    public function getStages(string $tripId): ?array
    {
        /** @var list<Stage>|null $value */
        $value = $this->get($this->stagesKey($tripId));

        return $value;
    }

    /**
     * Stores multi-track data for Komoot Collection source type.
     *
     * @param list<list<array{lat: float, lon: float, ele: float}>> $tracksData
     */
    public function storeTracksData(string $tripId, array $tracksData): void
    {
        $this->set($this->tracksDataKey($tripId), $tracksData);
    }

    /**
     * @return list<list<array{lat: float, lon: float, ele: float}>>|null
     */
    public function getTracksData(string $tripId): ?array
    {
        /** @var list<list<array{lat: float, lon: float, ele: float}>>|null $value */
        $value = $this->get($this->tracksDataKey($tripId));

        return $value;
    }

    public function storeSourceType(string $tripId, string $sourceType): void
    {
        $this->set($this->sourceTypeKey($tripId), $sourceType);
    }

    public function getSourceType(string $tripId): ?string
    {
        /** @var string|null $value */
        $value = $this->get($this->sourceTypeKey($tripId));

        return $value;
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

        if (!$item->isHit()) {
            return null;
        }

        // Refresh TTL on access
        $item->expiresAfter(self::TTL);
        $this->tripStateCache->save($item);

        return $item->get();
    }

    private function requestKey(string $tripId): string
    {
        return \sprintf('trip.%s.request', $tripId);
    }

    private function rawPointsKey(string $tripId): string
    {
        return \sprintf('trip.%s.raw_points', $tripId);
    }

    private function decimatedPointsKey(string $tripId): string
    {
        return \sprintf('trip.%s.decimated_points', $tripId);
    }

    private function stagesKey(string $tripId): string
    {
        return \sprintf('trip.%s.stages', $tripId);
    }

    private function sourceTypeKey(string $tripId): string
    {
        return \sprintf('trip.%s.source_type', $tripId);
    }

    private function tracksDataKey(string $tripId): string
    {
        return \sprintf('trip.%s.tracks_data', $tripId);
    }
}
