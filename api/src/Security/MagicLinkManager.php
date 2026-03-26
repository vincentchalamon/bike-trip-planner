<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\MagicLink;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages magic link lifecycle: creation, verification, and consumption.
 */
final readonly class MagicLinkManager
{
    private const int MAGIC_LINK_TTL_MINUTES = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Creates a magic link for the given user, if no active link already exists.
     *
     * Returns null if an active link is already pending (prevents link flooding).
     */
    public function create(User $user): ?MagicLink
    {
        if ($this->hasActiveLinkForUser($user)) {
            return null;
        }

        $token = bin2hex(random_bytes(64));
        $expiresAt = new \DateTimeImmutable(\sprintf('+%d minutes', self::MAGIC_LINK_TTL_MINUTES));

        $magicLink = new MagicLink($user, $token, $expiresAt);
        $this->entityManager->persist($magicLink);
        $this->entityManager->flush();

        return $magicLink;
    }

    /**
     * Verifies and consumes a magic link token.
     *
     * Returns the associated user if the token is valid, non-expired and not yet consumed.
     * The token is consumed atomically to prevent replay attacks.
     */
    public function verify(string $token): ?User
    {
        // Atomically consume the token: only succeeds if consumed_at is still NULL
        // and the token is not expired. This prevents TOCTOU race conditions where
        // two concurrent requests could both pass validation.
        $now = new \DateTimeImmutable();
        $affected = $this->entityManager->createQueryBuilder()
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

        $magicLink = $this->entityManager->getRepository(MagicLink::class)->findOneBy([
            'token' => $token,
        ]);

        return $magicLink?->getUser();
    }

    private function hasActiveLinkForUser(User $user): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(ml.id)')
            ->from(MagicLink::class, 'ml')
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
