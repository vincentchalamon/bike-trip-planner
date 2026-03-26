<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MagicLink;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagicLink>
 */
final class MagicLinkRepository extends ServiceEntityRepository
{
    public const int TTL_MINUTES = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagicLink::class);
    }

    /**
     * Creates a magic link for the given user, if no active link already exists.
     *
     * Persists the entity but does NOT flush — the caller is responsible for flushing.
     * Returns null if an active link is already pending (prevents link flooding).
     */
    public function create(User $user): ?MagicLink
    {
        if ($this->hasActiveLinkForUser($user)) {
            return null;
        }

        $token = bin2hex(random_bytes(64));
        $expiresAt = new \DateTimeImmutable(\sprintf('+%d minutes', self::TTL_MINUTES));

        $magicLink = new MagicLink($user, $token, $expiresAt);
        $this->getEntityManager()->persist($magicLink);

        return $magicLink;
    }

    /**
     * Atomically consumes a magic link token and returns the associated user.
     *
     * Uses a conditional UPDATE to prevent TOCTOU race conditions.
     * Returns null if the token is invalid, expired, or already consumed.
     */
    public function consumeByToken(string $token): ?User
    {
        $now = new \DateTimeImmutable();
        $affected = $this->getEntityManager()->createQueryBuilder()
            ->update(MagicLink::class, 'ml')
            ->set('ml.consumedAt', ':now')
            ->where('ml.token = :token')
            ->andWhere('ml.consumedAt IS NULL')
            ->andWhere('ml.expiresAt > :now')
            ->setParameter('token', $token)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        if (0 === $affected) {
            return null;
        }

        $user = $this->createQueryBuilder('ml')
            ->select('u')
            ->join('ml.user', 'u')
            ->where('ml.token = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
        \assert(null === $user || $user instanceof User);

        return $user;
    }

    private function hasActiveLinkForUser(User $user): bool
    {
        $count = $this->createQueryBuilder('ml')
            ->select('COUNT(ml.id)')
            ->where('ml.user = :user')
            ->andWhere('ml.consumedAt IS NULL')
            ->andWhere('ml.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
