<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessRequest;
use App\Enum\AccessRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessRequest>
 */
final class AccessRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequest::class);
    }

    public function findByEmail(string $email): ?AccessRequest
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * @return list<AccessRequest>
     */
    public function findVerified(
        ?\DateTimeImmutable $before = null,
        ?\DateTimeImmutable $after = null,
        ?string $emailPattern = null,
        int $page = 1,
        int $limit = 20,
    ): array {
        $qb = $this->createQueryBuilder('ar')
            ->where('ar.status = :status')
            ->setParameter('status', AccessRequestStatus::VERIFIED)
            ->orderBy('ar.verifiedAt', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($before instanceof \DateTimeImmutable) {
            $qb->andWhere('ar.verifiedAt < :before')
                ->setParameter('before', $before);
        }

        if ($after instanceof \DateTimeImmutable) {
            $qb->andWhere('ar.verifiedAt > :after')
                ->setParameter('after', $after);
        }

        if (null !== $emailPattern) {
            $qb->andWhere('ar.email LIKE :email')
                ->setParameter('email', '%'.$emailPattern.'%');
        }

        /** @var list<AccessRequest> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
