<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MagicLink;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * @extends ServiceEntityRepository<MagicLink>
 */
final class MagicLinkRepository extends ServiceEntityRepository
{
    public const int TTL_MINUTES = 30;

    public function __construct(
        ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
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
            $this->logger->debug('Magic link already active for user', ['user' => $user->getId()->toRfc4122()]);

            return null;
        }

        $plainToken = bin2hex(random_bytes(64));
        $expiresAt = $this->now()->modify(\sprintf('+%d minutes', self::TTL_MINUTES));

        // Store only the hash at rest (SEC-003): the plaintext travels in the
        // magic link and never touches the database.
        $magicLink = new MagicLink($user, hash('sha256', $plainToken), $expiresAt, plainToken: $plainToken);
        $this->getEntityManager()->persist($magicLink);

        $this->logger->debug('Magic link created', ['user' => $user->getId()->toRfc4122(), 'expires_at' => $expiresAt->format('c')]);

        return $magicLink;
    }

    /**
     * Atomically consumes a magic link token and returns the associated user.
     *
     * Uses a native SQL conditional UPDATE (SET consumed_at = :now WHERE
     * consumed_at IS NULL AND expires_at > :now) to prevent TOCTOU race
     * conditions — only the first concurrent request wins.
     *
     * Native SQL is used instead of DQL because Doctrine ORM 3 does not bind
     * DateTimeImmutable correctly in combined SET + WHERE clauses on PostgreSQL.
     */
    public function consumeByToken(string $token): ?User
    {
        // Format without offset (Y-m-d H:i:s) to match Doctrine's storage format
        // for TIMESTAMP WITHOUT TIME ZONE columns.
        $formatted = $this->now()->format('Y-m-d H:i:s');
        // The stored value is the hash of the token that travelled in the link.
        $tokenHash = hash('sha256', $token);
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE magic_link SET consumed_at = :now WHERE token = :token AND consumed_at IS NULL AND expires_at > :now',
            ['token' => $tokenHash, 'now' => $formatted],
        );

        if (0 === $affected) {
            $this->logger->debug('Magic link not found, expired, or already consumed');

            return null;
        }

        $magicLink = $this->findOneBy(['token' => $tokenHash]);

        $this->logger->debug('Magic link consumed', ['user' => $magicLink?->getUser()->getId()->toRfc4122()]);

        return $magicLink?->getUser();
    }

    /**
     * Marks all magic links for the given user for removal.
     *
     * Does NOT flush — the caller is responsible for flushing. Used by GDPR
     * erasure: the soft-delete (anonymise) does not trigger the FK ON DELETE
     * CASCADE, so lingering links would otherwise survive and could still be
     * consumed.
     */
    public function removeAllForUser(User $user): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(MagicLink::class, 'ml')
            ->where('ml.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    private function hasActiveLinkForUser(User $user): bool
    {
        $count = $this->createQueryBuilder('ml')
            ->select('COUNT(ml.id)')
            ->where('ml.user = :user')
            ->andWhere('ml.consumedAt IS NULL')
            ->andWhere('ml.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $this->now())
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * The one "now" every expiry here is written and compared with, pinned to UTC.
     *
     * `expires_at` is a TIMESTAMP WITHOUT TIME ZONE: Doctrine writes the wall-clock digits of
     * whatever zone the value carries, and consumeByToken() compares them to a UTC "now". An
     * expiry written in the PHP default zone drifted by that zone's offset: under
     * Europe/Paris a 30-minute link stayed consumable for two and a half hours.
     */
    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
