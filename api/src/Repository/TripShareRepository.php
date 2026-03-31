<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TripShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TripShare>
 */
final class TripShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TripShare::class);
    }

    /**
     * Find a valid (non-expired) share by trip ID and token.
     */
    public function findValidShare(string $tripId, string $token): ?TripShare
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.trip', 't')
            ->where('t.id = :tripId')
            ->andWhere('s.token = :token')
            ->setParameter('tripId', $tripId)
            ->setParameter('token', $token);

        /** @var TripShare|null $share */
        $share = $qb->getQuery()->getOneOrNullResult();

        if (!$share instanceof TripShare || !$share->isValid()) {
            return null;
        }

        return $share;
    }

    /**
     * Find all shares for a given trip.
     *
     * @return list<TripShare>
     */
    public function findByTrip(string $tripId): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.trip', 't')
            ->where('t.id = :tripId')
            ->setParameter('tripId', $tripId)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
