<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TripChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TripChatMessage>
 */
class TripChatMessageRepository extends ServiceEntityRepository
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TripChatMessage::class);
    }

    /**
     * Returns a page of chat turns for the given (trip, user) pair using a
     * `created_at DESC` cursor pagination scheme. When `$before` is provided
     * only messages strictly older than that timestamp are returned, so the
     * PWA can implement "load older messages" by passing the createdAt of the
     * oldest message already displayed.
     *
     * The composite index `(trip_id, user_id, created_at)` keeps the lookup
     * O(log n) even after months of in-ride consultations (PostgreSQL scans
     * B-tree indexes equally in either direction, so an ASC index serves the
     * DESC query without a separate descending index).
     *
     * The `id` tie-breaker guarantees deterministic pagination when two turns
     * land in the same TIMESTAMP(6) microsecond bucket (a single `flush()`
     * persists the user + assistant turn in one round-trip, so collisions are
     * realistic).
     *
     * The result is intentionally kept in DESC order (most-recent first) — the
     * frontend reverses it for chronological rendering. See issue #459.
     *
     * @return list<TripChatMessage>
     */
    public function findHistory(
        string $tripId,
        string $userId,
        int $limit = self::DEFAULT_LIMIT,
        ?\DateTimeImmutable $before = null,
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $qb = $this->createQueryBuilder('m')
            ->andWhere('IDENTITY(m.trip) = :tripId')
            ->andWhere('IDENTITY(m.user) = :userId')
            ->setParameter('tripId', $tripId)
            ->setParameter('userId', $userId)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(min($limit, self::MAX_LIMIT));

        if ($before instanceof \DateTimeImmutable) {
            $qb->andWhere('m.createdAt < :before')
                ->setParameter('before', $before);
        }

        /** @var list<TripChatMessage> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
