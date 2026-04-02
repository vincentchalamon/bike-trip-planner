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
        $this->queryBuilder->method('setMaxResults')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($this->query);

        $this->entityManager->method('createQueryBuilder')->willReturn($this->queryBuilder);

        $this->repository = new TripShareRepository($registry);
    }

    // --- findActiveByTrip ---

    #[Test]
    public function findActiveByTripReturnsActiveShare(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $trip = new TripRequest(Uuid::fromString($tripId));
        $share = new TripShare(trip: $trip, token: 'some-token');

        $this->query->method('getOneOrNullResult')->willReturn($share);

        $result = $this->repository->findActiveByTrip($tripId);

        $this->assertSame($share, $result);
    }

    #[Test]
    public function findActiveByTripReturnsNullWhenNoActiveShare(): void
    {
        $this->query->method('getOneOrNullResult')->willReturn(null);

        $result = $this->repository->findActiveByTrip('some-trip-id');

        $this->assertNull($result);
    }

    // --- TripShare entity ---

    #[Test]
    public function isActiveReturnsTrueByDefault(): void
    {
        $share = new TripShare();

        $this->assertTrue($share->isActive());
        $this->assertNull($share->getDeletedAt());
    }

    #[Test]
    public function softDeleteSetsDeletedAt(): void
    {
        $share = new TripShare();
        $share->softDelete();

        $this->assertFalse($share->isActive());
        $this->assertNotNull($share->getDeletedAt());
    }

    #[Test]
    public function generateTokenProduces64HexCharacters(): void
    {
        $share = new TripShare();
        $share->generateToken();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $share->getToken());
    }
}
