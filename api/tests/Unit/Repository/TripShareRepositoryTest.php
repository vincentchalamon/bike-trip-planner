<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use App\Repository\TripShareRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TripShareRepository::class)]
#[CoversClass(TripShare::class)]
final class TripShareRepositoryTest extends TestCase
{
    private TripShareRepository $repository;

    /** @var EntityManagerInterface&Stub */
    private EntityManagerInterface $entityManager;

    /** @var QueryBuilder&Stub */
    private QueryBuilder $queryBuilder;

    /** @var Query&Stub */
    private Query $query;

    #[\Override]
    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('getClassMetadata')
            ->willReturn(new ClassMetadata(TripShare::class));

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        $this->query = $this->createStub(Query::class);

        $this->queryBuilder = $this->createStub(QueryBuilder::class);
        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('join')->willReturnSelf();
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($this->query);

        $this->entityManager->method('createQueryBuilder')->willReturn($this->queryBuilder);

        $this->repository = new TripShareRepository($registry);
    }

    #[Test]
    public function findValidShareReturnsShareWhenTokenMatchesAndNotExpired(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $token = bin2hex(random_bytes(32));

        $trip = new TripRequest(Uuid::fromString($tripId));
        $share = new TripShare(trip: $trip, token: $token);

        $this->query->method('getOneOrNullResult')->willReturn($share);

        $result = $this->repository->findValidShare($tripId, $token);

        $this->assertSame($share, $result);
    }

    #[Test]
    public function findValidShareReturnsNullWhenQueryReturnsNull(): void
    {
        $this->query->method('getOneOrNullResult')->willReturn(null);

        $result = $this->repository->findValidShare('some-trip-id', 'some-token');

        $this->assertNull($result);
    }

    #[Test]
    public function findValidShareReturnsNullWhenShareIsExpired(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $trip = new TripRequest(Uuid::fromString($tripId));
        $expiredShare = new TripShare(
            trip: $trip,
            token: 'expired-token',
            expiresAt: new \DateTimeImmutable('-1 hour'),
        );

        $this->query->method('getOneOrNullResult')->willReturn($expiredShare);

        $result = $this->repository->findValidShare($tripId, 'expired-token');

        $this->assertNull($result);
    }

    #[Test]
    public function findValidShareReturnsShareWhenExpiryIsInTheFuture(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $token = bin2hex(random_bytes(32));

        $trip = new TripRequest(Uuid::fromString($tripId));
        $share = new TripShare(
            trip: $trip,
            token: $token,
            expiresAt: new \DateTimeImmutable('+7 days'),
        );

        $this->query->method('getOneOrNullResult')->willReturn($share);

        $result = $this->repository->findValidShare($tripId, $token);

        $this->assertSame($share, $result);
    }

    // --- TripShare::isValid() ---

    #[Test]
    public function isValidReturnsTrueWhenNoExpiry(): void
    {
        $share = new TripShare(expiresAt: null);

        $this->assertTrue($share->isValid());
    }

    #[Test]
    public function isValidReturnsTrueWhenExpiryIsInFuture(): void
    {
        $share = new TripShare(expiresAt: new \DateTimeImmutable('+1 day'));

        $this->assertTrue($share->isValid());
    }

    #[Test]
    public function isValidReturnsFalseWhenExpired(): void
    {
        $share = new TripShare(expiresAt: new \DateTimeImmutable('-1 second'));

        $this->assertFalse($share->isValid());
    }

    #[Test]
    public function generateTokenProduces64HexCharacters(): void
    {
        $share = new TripShare();
        $share->generateToken();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $share->getToken());
    }
}
