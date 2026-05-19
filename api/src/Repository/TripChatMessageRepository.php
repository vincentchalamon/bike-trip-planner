<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TripChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TripChatMessage>
 */
final class TripChatMessageRepository extends ServiceEntityRepository
{
    public const int DEFAULT_LIMIT = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TripChatMessage::class);
    }

    /**
     * Returns the most recent chat turns for the given (trip, user) pair,
     * ordered oldest first so the frontend can render the timeline directly.
     *
     * The composite index `(trip_id, user_id, created_at DESC)` keeps the lookup
     * O(log n) even after months of in-ride consultations.
     *
     * @return list<TripChatMessage>
     */
    public function findByTrip(string $tripId, string $userId, int $limit = self::DEFAULT_LIMIT): array
    {
        if ($limit <= 0) {
            return [];
        }

        /** @var list<TripChatMessage> $recent */
        $recent = $this->createQueryBuilder('m')
            ->andWhere('IDENTITY(m.trip) = :tripId')
            ->andWhere('IDENTITY(m.user) = :userId')
            ->setParameter('tripId', $tripId)
            ->setParameter('userId', $userId)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($recent);
    }
}
