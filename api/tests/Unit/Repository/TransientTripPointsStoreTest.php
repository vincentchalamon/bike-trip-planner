<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\TransientTripPointsStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TransientTripPointsStore::class)]
final class TransientTripPointsStoreTest extends TestCase
{
    private CacheItemPoolInterface&MockObject $cache;

    private TransientTripPointsStore $store;

    #[\Override]
    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->store = new TransientTripPointsStore($this->cache);
    }

    #[Test]
    public function rawPointsUsesCache(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $cacheKey = \sprintf('trip.%s.raw_points', $tripId);
        $rawPoints = [
            ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
            ['lat' => 47.9983, 'lon' => 3.5736, 'ele' => 180.0],
        ];

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects(self::once())
            ->method('set')
            ->with($rawPoints);
        $cacheItem->expects(self::once())
            ->method('expiresAfter')
            ->with(1800);

        $this->cache->expects(self::once())
            ->method('getItem')
            ->with($cacheKey)
            ->willReturn($cacheItem);
        $this->cache->expects(self::once())
            ->method('save')
            ->with($cacheItem);


        $this->store->storeRawPoints($tripId, $rawPoints);
    }

    #[Test]
    public function getRawPointsReturnsCachedData(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $cacheKey = \sprintf('trip.%s.raw_points', $tripId);
        $rawPoints = [
            ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
        ];

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($rawPoints);

        $this->cache->expects(self::once())
            ->method('getItem')
            ->with($cacheKey)
            ->willReturn($cacheItem);

        $result = $this->store->getRawPoints($tripId);

        self::assertSame($rawPoints, $result);
    }

    #[Test]
    public function getRawPointsReturnsNullOnCacheMiss(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $cacheKey = \sprintf('trip.%s.raw_points', $tripId);

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache->expects(self::once())
            ->method('getItem')
            ->with($cacheKey)
            ->willReturn($cacheItem);

        $result = $this->store->getRawPoints($tripId);

        self::assertNull($result);
    }
}
