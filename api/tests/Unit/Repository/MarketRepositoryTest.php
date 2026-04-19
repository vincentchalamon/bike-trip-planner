<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use App\Entity\Market;
use App\Repository\MarketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarketRepository::class)]
final class MarketRepositoryTest extends TestCase
{
    private MarketRepository $repository;

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
            ->willReturn(new ClassMetadata(Market::class));

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        $this->query = $this->createStub(Query::class);

        $this->queryBuilder = $this->createStub(QueryBuilder::class);
        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($this->query);

        $this->entityManager->method('createQueryBuilder')->willReturn($this->queryBuilder);

        $this->repository = new MarketRepository($registry);
    }

    #[Test]
    public function findNearEndpointFiltersOutMarketsBeyondRadius(): void
    {
        $marketClose = $this->makeMarket('MKT-1', 48.5, 2.5, 3);
        $marketFar = $this->makeMarket('MKT-2', 52.0, 5.0, 3);

        $this->query->method('getResult')->willReturn([$marketClose, $marketFar]);

        $results = $this->repository->findNearEndpoint(48.5, 2.5, 20_000, 3);

        $this->assertCount(1, $results);
        $this->assertSame('MKT-1', $results[0]->getExternalId());
    }

    #[Test]
    public function findNearEndpointReturnsEmptyWhenNoMarketsInBbox(): void
    {
        $this->query->method('getResult')->willReturn([]);

        $results = $this->repository->findNearEndpoint(48.5, 2.5, 20_000, 2);

        $this->assertCount(0, $results);
    }

    #[Test]
    public function findNearEndpointOnlyIncludesMatchingDayOfWeek(): void
    {
        // The day-of-week filter happens in the DQL query (mocked), so this verifies
        // that only markets returned by the query (already filtered by day) pass through.
        $marketWedThursday = $this->makeMarket('MKT-3', 48.5, 2.5, 4);

        $this->query->method('getResult')->willReturn([$marketWedThursday]);

        $results = $this->repository->findNearEndpoint(48.5, 2.5, 20_000, 4);

        $this->assertCount(1, $results);
        $this->assertSame(4, $results[0]->getDayOfWeek());
    }

    #[Test]
    public function findByExternalIdReturnsNullWhenNotFound(): void
    {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getEntityPersister')->willReturn(
            $this->createConfiguredStub(EntityPersister::class, [
                'load' => null,
            ])
        );
        $this->entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        $result = $this->repository->findByExternalId('NON-EXISTENT');

        $this->assertNull($result);
    }

    private function makeMarket(string $externalId, float $lat, float $lon, int $dayOfWeek): Market
    {
        $market = new Market($externalId, 'Test Market');
        $market->setLat($lat);
        $market->setLon($lon);
        $market->setDayOfWeek($dayOfWeek);
        $market->setCommune('Test');
        $market->setDepartment('00');

        return $market;
    }
}
