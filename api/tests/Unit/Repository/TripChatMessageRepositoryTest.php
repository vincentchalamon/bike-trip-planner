<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\ApiResource\TripRequest;
use App\Entity\TripChatMessage;
use App\Entity\User;
use App\Repository\TripChatMessageRepository;
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

#[CoversClass(TripChatMessageRepository::class)]
final class TripChatMessageRepositoryTest extends TestCase
{
    private TripChatMessageRepository $repository;

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
            ->willReturn(new ClassMetadata(TripChatMessage::class));

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);

        $this->query = $this->createStub(Query::class);

        $this->queryBuilder = $this->createStub(QueryBuilder::class);
        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('orderBy')->willReturnSelf();
        $this->queryBuilder->method('setParameter')->willReturnSelf();
        $this->queryBuilder->method('setMaxResults')->willReturnSelf();
        $this->queryBuilder->method('getQuery')->willReturn($this->query);

        $this->entityManager->method('createQueryBuilder')->willReturn($this->queryBuilder);

        $this->repository = new TripChatMessageRepository($registry);
    }

    #[Test]
    public function findByTripReturnsEmptyArrayWhenLimitIsZero(): void
    {
        $messages = $this->repository->findByTrip(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            0,
        );

        self::assertSame([], $messages);
    }

    #[Test]
    public function findByTripReturnsEmptyArrayWhenLimitIsNegative(): void
    {
        $messages = $this->repository->findByTrip(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            -5,
        );

        self::assertSame([], $messages);
    }

    #[Test]
    public function findByTripReversesMostRecentResultsForChronologicalOrder(): void
    {
        $trip = new TripRequest();
        $user = new User('rider@example.com', Uuid::v7());

        $oldest = $this->makeMessage($trip, $user, TripChatMessage::ROLE_USER, 'Bonjour', '-3 minutes');
        $middle = $this->makeMessage($trip, $user, TripChatMessage::ROLE_ASSISTANT, 'Hello', '-2 minutes');
        $newest = $this->makeMessage($trip, $user, TripChatMessage::ROLE_USER, 'Encore', '-1 minute');

        // The query orders DESC (most recent first); the repository reverses the
        // result so the caller can render the timeline oldest → newest directly.
        $this->query->method('getResult')->willReturn([$newest, $middle, $oldest]);

        $messages = $this->repository->findByTrip(
            $trip->id->toRfc4122(),
            $user->getId()->toRfc4122(),
        );

        self::assertCount(3, $messages);
        self::assertSame('Bonjour', $messages[0]->getContent());
        self::assertSame('Hello', $messages[1]->getContent());
        self::assertSame('Encore', $messages[2]->getContent());
    }

    #[Test]
    public function findByTripReturnsEmptyArrayWhenQueryYieldsNoRow(): void
    {
        $this->query->method('getResult')->willReturn([]);

        $messages = $this->repository->findByTrip(
            Uuid::v7()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
        );

        self::assertSame([], $messages);
    }

    private function makeMessage(
        TripRequest $trip,
        User $user,
        string $role,
        string $content,
        string $createdAtModifier,
    ): TripChatMessage {
        return new TripChatMessage(
            trip: $trip,
            user: $user,
            role: $role,
            content: $content,
            createdAt: new \DateTimeImmutable($createdAtModifier),
        );
    }
}
