<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IdempotencyKey;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<IdempotencyKey>
 */
final class IdempotencyKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IdempotencyKey::class);
    }

    public function findRecorded(User $user, string $operation, string $key): ?IdempotencyKey
    {
        /** @var IdempotencyKey|null $recorded */
        $recorded = $this->findOneBy([
            'user' => $user,
            'operation' => $operation,
            'key' => $key,
        ]);

        return $recorded;
    }

    /**
     * Ties the key to what it created, and returns the identifier the caller must answer with:
     * $resourceId, or the one recorded by a concurrent call that wrote the same key first.
     *
     * Inserted through the connection rather than persisted through the ORM, because the losing
     * insert is the whole point and `EntityManager::flush()` does not survive it:
     * `UnitOfWork::commit()` closes the entity manager in its `finally` before the exception
     * propagates (orm/src/UnitOfWork.php:470-472). Every Doctrine read after that — the one below,
     * and everything the calling processor still has to do — would throw `EntityManagerClosed`,
     * so the race this method exists to handle would answer 500. The connection is only rolled
     * back, never closed. It also keeps the write from carrying along whatever else the unit of
     * work happens to hold.
     */
    public function record(User $user, string $operation, string $key, string $requestDigest, Uuid $resourceId, \DateTimeImmutable $createdAt): Uuid
    {
        $connection = $this->getEntityManager()->getConnection();

        // Outside any transaction: a violated INSERT aborts the enclosing one in Postgres, and
        // the recovery read below would fail with it. Both creating processors commit first.
        \assert(!$connection->isTransactionActive());

        try {
            $connection->insert('idempotency_key', [
                'id' => Uuid::v7(),
                'user_id' => $user->getId(),
                'operation' => $operation,
                'idempotency_key' => $key,
                'request_digest' => $requestDigest,
                'resource_id' => $resourceId,
                'created_at' => $createdAt,
            ], [
                'id' => UuidType::NAME,
                'user_id' => UuidType::NAME,
                'resource_id' => UuidType::NAME,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            $recorded = $this->findRecorded($user, $operation, $key);

            return $recorded instanceof IdempotencyKey ? $recorded->resourceId : $resourceId;
        }

        return $resourceId;
    }

    /**
     * Drops keys past their retention window.
     *
     * A key is a memory of a request, not a record of anything: past the window a replay is no
     * longer a retry, it is a new intent. Twenty-four hours is what the draft suggests and what
     * the purge command passes.
     */
    public function purgeOlderThan(\DateTimeImmutable $cutoff): int
    {
        $deleted = $this->createQueryBuilder('k')
            ->delete()
            ->where('k.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }
}
