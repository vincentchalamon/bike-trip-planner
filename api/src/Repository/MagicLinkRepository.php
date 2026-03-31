<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MagicLink;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * @extends ServiceEntityRepository<MagicLink>
 */
final class MagicLinkRepository extends ServiceEntityRepository
{
    private const int TTL_MINUTES = 30;

    public function __construct(
        ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
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
            $this->logger->debug('Magic link already active for user', ['email' => $user->getEmail()]);

            return null;
        }

        $token = bin2hex(random_bytes(64));
        $expiresAt = new \DateTimeImmutable(\sprintf('+%d minutes', self::TTL_MINUTES));

        $magicLink = new MagicLink($user, $token, $expiresAt);
        $this->getEntityManager()->persist($magicLink);

        $this->logger->debug('Magic link created', ['email' => $user->getEmail(), 'expires_at' => $expiresAt->format('c')]);

        return $magicLink;
    }

    /**
     * Atomically consumes a magic link token and returns the associated user.
     *
     * Uses a native SQL conditional UPDATE (SET consumed_at = NOW() WHERE
     * consumed_at IS NULL AND expires_at > NOW()) to prevent TOCTOU race
     * conditions — only the first concurrent request wins.
     *
     * Native SQL is used instead of DQL because Doctrine ORM 3 does not bind
     * DateTimeImmutable correctly in combined SET + WHERE clauses on PostgreSQL.
     */
    public function consumeByToken(string $token): ?User
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE magic_link SET consumed_at = :now WHERE token = :token AND consumed_at IS NULL AND expires_at > :now',
            ['token' => $token, 'now' => $now->format('Y-m-d H:i:sP')],
        );

        if (0 === $affected) {
            $this->logger->debug('Magic link not found, expired, or already consumed');

            return null;
        }

        $magicLink = $this->findOneBy(['token' => $token]);

        $this->logger->debug('Magic link consumed', ['email' => $magicLink?->getUser()->getEmail()]);

        return $magicLink?->getUser();
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
