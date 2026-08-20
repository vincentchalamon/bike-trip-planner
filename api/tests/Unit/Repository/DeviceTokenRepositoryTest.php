<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Enum\DevicePlatform;
use App\Repository\DeviceTokenRepository;
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

#[CoversClass(DeviceTokenRepository::class)]
final class DeviceTokenRepositoryTest extends TestCase
{
    private DeviceTokenRepository $repository;

    /** @var QueryBuilder&Stub */
    private QueryBuilder $queryBuilder;

    /** @var Query&Stub */
    private Query $query;

    /** @var array<string, mixed> */
    private array $parameters = [];

    private ?string $deleteDql = null;

    private ?string $whereDql = null;

    private ?string $andWhereDql = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->parameters = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn(new ClassMetadata(DeviceToken::class));

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        $this->query = $this->createStub(Query::class);

        $this->queryBuilder = $this->createStub(QueryBuilder::class);
        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('delete')->willReturnCallback(function (): QueryBuilder {
            $this->deleteDql = 'delete';

            return $this->queryBuilder;
        });
        $this->queryBuilder->method('where')->willReturnCallback(function (string $dql): QueryBuilder {
            $this->whereDql = $dql;

            return $this->queryBuilder;
        });
        $this->queryBuilder->method('andWhere')->willReturnCallback(function (string $dql): QueryBuilder {
            $this->andWhereDql = $dql;

            return $this->queryBuilder;
        });
        $this->queryBuilder->method('setParameter')->willReturnCallback(function (string $key, mixed $value): QueryBuilder {
            $this->parameters[$key] = $value;

            return $this->queryBuilder;
        });
        $this->queryBuilder->method('getQuery')->willReturn($this->query);

        $entityManager->method('createQueryBuilder')->willReturn($this->queryBuilder);

        $this->repository = new DeviceTokenRepository($registry);
    }

    #[Test]
    public function findByUserIdFiltersOnTheUserIdentityAndReturnsTheResult(): void
    {
        $user = new User('rider@example.com');
        $token = new DeviceToken($user, 'tok-1', DevicePlatform::ANDROID);
        $this->query->method('getResult')->willReturn([$token]);

        $userId = Uuid::v7()->toRfc4122();
        $result = $this->repository->findByUserId($userId);

        self::assertSame([$token], $result);
        self::assertSame('IDENTITY(dt.user) = :userId', $this->andWhereDql);
        self::assertSame($userId, $this->parameters['userId']);
    }

    #[Test]
    public function deleteByTokensBuildsABulkDeleteBoundToTheTokenList(): void
    {
        $this->query->method('execute')->willReturn(2);

        $this->repository->deleteByTokens(['dead-1', 'dead-2']);

        self::assertSame('delete', $this->deleteDql);
        self::assertSame('dt.token IN (:tokens)', $this->whereDql);
        self::assertSame(['dead-1', 'dead-2'], $this->parameters['tokens']);
    }

    #[Test]
    public function deleteByTokensIsANoOpOnAnEmptyList(): void
    {
        // No queued execute(): building a query would fail the stub, so an empty
        // list must short-circuit before touching the query builder.
        $this->repository->deleteByTokens([]);

        self::assertNull($this->deleteDql);
        self::assertSame([], $this->parameters);
    }
}
