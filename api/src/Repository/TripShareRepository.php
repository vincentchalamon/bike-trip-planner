<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TripShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TripShare>
 */
final class TripShareRepository extends ServiceEntityRepository implements TripShareRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TripShare::class);
    }

    public function findValidShare(string $tripId, string $token): ?TripShare
    {
        /** @var TripShare|null $share */
        $share = $this->createQueryBuilder('s')
            ->join('s.trip', 't')
            ->where('t.id = :tripId')
            ->andWhere('s.token = :token')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('tripId', $tripId)
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();

        return $share;
    }

    public function findActiveByTrip(string $tripId): ?TripShare
    {
        /** @var TripShare|null $share */
        $share = $this->createQueryBuilder('s')
            ->join('s.trip', 't')
            ->where('t.id = :tripId')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('tripId', $tripId)
            ->getQuery()
            ->getOneOrNullResult();

        return $share;
    }

    public function findByShortCode(string $shortCode): ?TripShare
    {
        /** @var TripShare|null $share */
        $share = $this->createQueryBuilder('s')
            ->where('s.shortCode = :shortCode')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('shortCode', $shortCode)
            ->getQuery()
            ->getOneOrNullResult();

        return $share;
    }
}
