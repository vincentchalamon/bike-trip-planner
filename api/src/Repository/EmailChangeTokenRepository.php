<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailChangeToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * @extends ServiceEntityRepository<EmailChangeToken>
 */
final class EmailChangeTokenRepository extends ServiceEntityRepository
{
    /**
     * Lifetime of an email-change confirmation link. Single source of truth:
     * referenced by both the token expiry here and the value rendered in the
     * confirmation email (see RequestEmailChangeProcessor).
     */
    public const int TTL_MINUTES = 30;

    public function __construct(
        ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($registry, EmailChangeToken::class);
    }

    /**
     * Creates an email-change token for the given user + target address.
     *
     * Invalidates any pending (unconsumed, unexpired) token for the same user
     * first, so the latest request always wins and stale links stop working.
     * Persists the entity but does NOT flush — the caller flushes.
     */
    public function create(User $user, string $newEmail): EmailChangeToken
    {
        $this->expirePendingForUser($user);

        $token = bin2hex(random_bytes(64));
        $expiresAt = new \DateTimeImmutable(\sprintf('+%d minutes', self::TTL_MINUTES), new \DateTimeZone('UTC'));

        $emailChangeToken = new EmailChangeToken($user, $token, $newEmail, $expiresAt);
        $this->getEntityManager()->persist($emailChangeToken);

        $this->logger->debug('Email change token created', ['user' => $user->getId()->toRfc4122(), 'expires_at' => $expiresAt->format('c')]);

        return $emailChangeToken;
    }

    /**
     * Returns a still-usable token (exists, unconsumed, unexpired) WITHOUT
     * consuming it, so the caller can check ownership before committing the
     * single-use consumption. Returns null when missing, expired or already
     * consumed.
     */
    public function findValidByToken(string $token): ?EmailChangeToken
    {
        $entity = $this->findOneBy(['token' => $token]);

        if (!$entity instanceof EmailChangeToken || !$entity->isValid()) {
            return null;
        }

        return $entity;
    }

    /**
     * Atomically consumes an email-change token that belongs to the given user.
     *
     * Uses a native SQL conditional UPDATE (SET consumed_at = :now WHERE
     * user_id = :user AND consumed_at IS NULL AND expires_at > :now) to prevent
     * TOCTOU races — only the first concurrent request wins, guaranteeing single
     * use. Scoping by user_id means a token belonging to someone else is never
     * consumed here (ownership is rejected upstream without side effects).
     *
     * Native SQL is used instead of DQL because Doctrine ORM 3 does not bind
     * DateTimeImmutable correctly in combined SET + WHERE clauses on PostgreSQL.
     *
     * @return bool true when this call consumed the token, false otherwise
     *              (already consumed, expired, or not owned by the user)
     */
    public function consumeByTokenForUser(string $token, User $user): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $formatted = $now->format('Y-m-d H:i:s');
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE email_change_token SET consumed_at = :now WHERE token = :token AND user_id = :user AND consumed_at IS NULL AND expires_at > :now',
            ['token' => $token, 'user' => $user->getId()->toRfc4122(), 'now' => $formatted],
        );

        if (0 === $affected) {
            $this->logger->debug('Email change token not consumed (expired, already consumed, or not owned)');

            return false;
        }

        return true;
    }

    /**
     * Marks all email-change tokens for the given user as consumed (pending or
     * not) so a freshly issued request supersedes any in-flight link.
     */
    private function expirePendingForUser(User $user): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->update(EmailChangeToken::class, 'ect')
            ->set('ect.consumedAt', ':now')
            ->where('ect.user = :user')
            ->andWhere('ect.consumedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
