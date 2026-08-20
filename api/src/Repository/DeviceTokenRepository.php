<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeviceToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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
            // Wrap in Uuid so the parameter round-trips through the `uuid` DBAL type
            // like every other uuid-column comparison here; a raw string risks a type
            // error or a silently-never-matching query (ADR-058: no silent no-op).
            ->setParameter('userId', Uuid::fromString($userId))
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
