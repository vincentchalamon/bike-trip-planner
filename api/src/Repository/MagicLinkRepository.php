<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MagicLink;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Manages the 0..1 relationship between User and MagicLink.
 *
 * A user has at most one active (non-expired) magic link at any time.
 * Consumed links are deleted immediately; expired links are cleaned up
 * opportunistically when a new link is created.
 *
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
     * Expired links are cleaned up opportunistically.
     * Persists the entity but does NOT flush — the caller is responsible for flushing.
     * Returns null if an active link is already pending (prevents link flooding).
     */
    public function create(User $user): ?MagicLink
    {
        // Clean up any expired links for this user
        $this->getEntityManager()->createQueryBuilder()
            ->delete(MagicLink::class, 'ml')
            ->where('ml.user = :user')
            ->andWhere('ml.expiresAt <= :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();

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
     * Atomically consumes and deletes a magic link token, returning the associated user.
     *
     * Uses a conditional DELETE to prevent TOCTOU race conditions: only the first
     * concurrent request will delete the row.
     *
     * Returns null if the token is invalid, expired, or already consumed.
     */
    public function consumeByToken(string $token): ?User
    {
        // Fetch the magic link with its user eagerly before deleting
        $magicLink = $this->createQueryBuilder('ml')
            ->addSelect('u')
            ->join('ml.user', 'u')
            ->where('ml.token = :token')
            ->andWhere('ml.expiresAt > :now')
            ->setParameter('token', $token)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();

        if (!$magicLink instanceof MagicLink) {
            return null;
        }

        $user = $magicLink->getUser();

        // Atomically delete the token: only the first concurrent request deletes 1 row
        $affected = $this->getEntityManager()->createQueryBuilder()
            ->delete(MagicLink::class, 'ml')
            ->where('ml.token = :token')
            ->andWhere('ml.expiresAt > :now')
            ->setParameter('token', $token)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();

        if (0 === $affected) {
            return null; // Lost the race — another request consumed it first
        }

        return $user;
    }

    private function hasActiveLinkForUser(User $user): bool
    {
        $count = $this->createQueryBuilder('ml')
            ->select('COUNT(ml.id)')
            ->where('ml.user = :user')
            ->andWhere('ml.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
