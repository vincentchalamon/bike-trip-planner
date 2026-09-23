<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IdempotencyKey;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
