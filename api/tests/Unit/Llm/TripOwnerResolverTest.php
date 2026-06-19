<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Llm\TripOwnerResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Uid\Uuid;

final class TripOwnerResolverTest extends TestCase
{
    private const string TRIP_ID = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    #[Test]
    public function returnsNullForAnInvalidTripId(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::never())->method('getItem');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('find');

        self::assertNull(new TripOwnerResolver($cache, $em)->resolve('not-a-uuid'));
    }

    #[Test]
    public function returnsTheUserFromTheRedisOwnerId(): void
    {
        $user = new User('rider@example.test');
        $cache = $this->cacheReturning($user->getId()->toRfc4122());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('find')
            ->with(User::class, self::callback(static fn (Uuid $id): bool => $id->equals($user->getId())))
            ->willReturn($user);

        self::assertSame($user, new TripOwnerResolver($cache, $em)->resolve(self::TRIP_ID));
    }

    #[Test]
    public function fallsBackToPostgresWhenTheCachedUserNoLongerExists(): void
    {
        // Redis hit, but the user_id points to a row deleted since: fall through.
        $owner = new User('owner@example.test');
        $trip = new TripRequest();
        $trip->user = $owner;

        $cache = $this->cacheReturning(Uuid::v7()->toRfc4122());

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $class): ?object => User::class === $class ? null : $trip);

        self::assertSame($owner, new TripOwnerResolver($cache, $em)->resolve(self::TRIP_ID));
    }

    #[Test]
    public function fallsBackToThePostgresTripOwnerOnCacheMiss(): void
    {
        $owner = new User('owner@example.test');
        $trip = new TripRequest();
        $trip->user = $owner;

        $cache = $this->cacheMiss();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('find')
            ->with(TripRequest::class, self::isInstanceOf(Uuid::class))
            ->willReturn($trip);

        self::assertSame($owner, new TripOwnerResolver($cache, $em)->resolve(self::TRIP_ID));
    }

    #[Test]
    public function returnsNullWhenNoOwnerCanBeResolved(): void
    {
        $cache = $this->cacheMiss();

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        self::assertNull(new TripOwnerResolver($cache, $em)->resolve(self::TRIP_ID));
    }

    private function cacheReturning(string $userId): CacheItemPoolInterface
    {
        $item = $this->createStub(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($userId);

        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);

        return $cache;
    }

    private function cacheMiss(): CacheItemPoolInterface
    {
        $item = $this->createStub(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);

        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);

        return $cache;
    }
}
