<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use PHPUnit\Framework\MockObject\Stub;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Security\Voter\TripVoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

final class TripVoterTest extends TestCase
{
    /** @var EntityManagerInterface&Stub */
    private EntityManagerInterface $entityManager;

    /** @var CacheItemPoolInterface&Stub */
    private CacheItemPoolInterface $tripStateCache;

    private TripVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->tripStateCache = $this->createStub(CacheItemPoolInterface::class);
        $this->voter = new TripVoter($this->entityManager, $this->tripStateCache);
    }

    #[Test]
    public function abstainWhenAttributeNotSupported(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $subject = new TripRequest();

        $result = $this->voter->vote($token, $subject, ['UNSUPPORTED_ATTR']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function denyWhenUserIsNotAuthenticated(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $subject = new TripRequest();
        $subject->id = Uuid::fromString('01936f6e-0000-7000-8000-000000000001');

        $result = $this->voter->vote($token, $subject, [TripVoter::TRIP_VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function denyWhenSubjectIsEmptyString(): void
    {
        $user = new User('test@example.com');
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, '', [TripVoter::TRIP_VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function grantWhenOwnerFoundInDatabase(): void
    {
        $userId = Uuid::v7();
        $user = new User('owner@example.com', $userId);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tripId = '01936f6e-0000-7000-8000-000000000001';
        $subject = new TripRequest();
        $subject->id = Uuid::fromString($tripId);

        $this->mockDatabaseOwnershipCheck(1);

        // Redis must not be consulted when the DB check succeeds
        $tripStateCache = $this->createMock(CacheItemPoolInterface::class);
        $tripStateCache->expects($this->never())->method('getItem');
        $this->voter = new TripVoter($this->entityManager, $tripStateCache);

        $result = $this->voter->vote($token, $subject, [TripVoter::TRIP_EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function grantWhenOwnerFoundInRedis(): void
    {
        $userId = Uuid::v7();
        $user = new User('owner@example.com', $userId);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tripId = '01936f6e-0000-7000-8000-000000000002';
        $subject = new TripRequest();
        $subject->id = Uuid::fromString($tripId);

        $this->mockDatabaseOwnershipCheck(0);

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($userId->toRfc4122());

        $this->tripStateCache
            ->method('getItem')
            ->willReturn($cacheItem);

        $result = $this->voter->vote($token, $subject, [TripVoter::TRIP_DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function denyWhenNotFoundInDatabaseOrRedis(): void
    {
        $user = new User('stranger@example.com');
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tripId = '01936f6e-0000-7000-8000-000000000003';
        $subject = new TripRequest();
        $subject->id = Uuid::fromString($tripId);

        $this->mockDatabaseOwnershipCheck(0);

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->tripStateCache->method('getItem')->willReturn($cacheItem);

        $result = $this->voter->vote($token, $subject, [TripVoter::TRIP_VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function grantWhenOwnerFoundInDatabaseViaStringSubject(): void
    {
        $userId = Uuid::v7();
        $user = new User('owner@example.com', $userId);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tripId = '01936f6e-0000-7000-8000-000000000004';

        $this->mockDatabaseOwnershipCheck(1);

        $tripStateCache = $this->createMock(CacheItemPoolInterface::class);
        $tripStateCache->expects($this->never())->method('getItem');
        $this->voter = new TripVoter($this->entityManager, $tripStateCache);

        // Stage operations pass the tripId as a plain string, not a TripRequest
        $result = $this->voter->vote($token, $tripId, [TripVoter::TRIP_VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    private function mockDatabaseOwnershipCheck(int $count): void
    {
        $query = $this->createStub(Query::class);
        $query->method('getSingleScalarResult')->willReturn($count);

        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->entityManager->method('createQueryBuilder')->willReturn($qb);
    }
}
