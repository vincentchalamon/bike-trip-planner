<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeviceToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeviceToken>
 */
final class DeviceTokenRepository extends ServiceEntityRepository implements DeviceTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceToken::class);
    }

    public function findOneByToken(string $token): ?DeviceToken
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function findOneOwnedByUser(string $token, User $user): ?DeviceToken
    {
        return $this->findOneBy(['token' => $token, 'user' => $user]);
    }

    /**
     * @return list<DeviceToken>
     */
    public function findByUserId(string $userId): array
    {
        /** @var list<DeviceToken> $tokens */
        $tokens = $this->createQueryBuilder('dt')
            ->andWhere('IDENTITY(dt.user) = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return $tokens;
    }

    /**
     * Removes every device token whose value is in the given list (FCM reported
     * them UNREGISTERED / 404). No-op on an empty list.
     *
     * @param list<string> $tokens
     */
    public function deleteByTokens(array $tokens): void
    {
        if ([] === $tokens) {
            return;
        }

        $this->createQueryBuilder('dt')
            ->delete()
            ->where('dt.token IN (:tokens)')
            ->setParameter('tokens', $tokens)
            ->getQuery()
            ->execute();
    }
}
