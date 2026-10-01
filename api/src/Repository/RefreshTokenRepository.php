<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Security\RefreshTokenEncryptor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
final class RefreshTokenRepository extends ServiceEntityRepository
{
    /**
     * How long a person stays signed in on a device without touching the magic-link flow again.
     *
     * ⚠ Unrelated to `league_oauth2_server.refresh_token_ttl` (`P1M`), and their near-equality
     * is a coincidence, not a coupling (#1309). That one answers a different question — how
     * long an agent may come back without a fresh consent (ADR-079/ADR-082) — so moving this
     * number is a product decision about sessions, and moving that one is a security decision
     * about delegated access. Neither should drag the other along.
     */
    private const int TTL_DAYS = 30;

    public function __construct(
        ManagerRegistry $registry,
        private readonly RefreshTokenEncryptor $encryptor,
    ) {
        parent::__construct($registry, RefreshToken::class);
    }

    /**
     * Creates a new refresh token for the given user. The token is stored
     * encrypted at rest and looked up by its digest; the plaintext is kept on
     * the returned entity ({@see RefreshToken::getPlainToken()}) for immediate
     * re-serving to the client.
     */
    public function createForUser(User $user): RefreshToken
    {
        $refreshToken = $this->issue($user);
        $this->getEntityManager()->persist($refreshToken);
        $this->getEntityManager()->flush();

        return $refreshToken;
    }

    /**
     * Rotates $existing: issues its successor, and keeps $existing usable for $graceSeconds
     * pointing at it, so a reload race that re-sends the pre-rotation token resolves to the
     * successor rather than a 401 that destroys the session (recette #649).
     *
     * Returns the successor, or, when a concurrent call rotated first, the live successor that
     * call issued: null if that one is no longer valid either.
     *
     * The claim is a compare-and-swap in SQL: only the first concurrent caller flips
     * replaced_by_token from NULL, which fences a double rotation that would otherwise orphan a
     * live 30-day successor, and the successor is inserted in the same transaction. "Now" is the
     * PHP clock on both sides of it, the one every expiry here is written with and that
     * {@see RefreshToken::isValid()} reads: the database's NOW() runs in the session's zone,
     * which need not be PHP's, and `expires_at` carries no zone to reconcile the two.
     */
    public function rotate(RefreshToken $existing, int $graceSeconds): ?RefreshToken
    {
        $now = new \DateTimeImmutable();
        $grace = $now->modify(\sprintf('+%d seconds', $graceSeconds));
        $successor = $this->issue($existing->getUser());
        $em = $this->getEntityManager();
        $claimed = 0;

        $em->wrapInTransaction(static function () use ($em, $existing, $successor, $grace, $now, &$claimed): void {
            $claimed = $em->getConnection()->executeStatement(
                'UPDATE refresh_token SET replaced_by_token = :new, expires_at = :grace WHERE id = :id AND replaced_by_token IS NULL AND expires_at > :now',
                [
                    'new' => $successor->getTokenDigest(),
                    'grace' => $grace,
                    'id' => $existing->getId(),
                    'now' => $now,
                ],
                [
                    'grace' => Types::DATETIME_IMMUTABLE,
                    'id' => UuidType::NAME,
                    'now' => Types::DATETIME_IMMUTABLE,
                ],
            );

            if (1 === $claimed) {
                // Mirrored onto the managed entity so the flush closing the transaction does
                // not write the old values back.
                $existing->replaceWith($successor->getTokenDigest(), $grace);
                $em->persist($successor);
            }
        });

        if (1 === $claimed) {
            return $successor;
        }

        $em->refresh($existing);
        $replacedBy = $existing->getReplacedByToken();

        return null !== $replacedBy ? $this->findValidByDigest($replacedBy) : null;
    }

    /**
     * Finds a valid (non-expired) refresh token by its plaintext token string,
     * matching on the stored digest.
     */
    public function findValidByToken(#[\SensitiveParameter] string $token): ?RefreshToken
    {
        return $this->findValidByDigest(RefreshTokenEncryptor::digest($token));
    }

    /**
     * Finds a refresh token by plaintext INCLUDING expired/rotated ones. Used to
     * tell a replay of an already-rotated token (reuse) from a merely-unknown
     * token, so the processor can revoke the whole family on reuse.
     */
    public function findAnyByToken(#[\SensitiveParameter] string $token): ?RefreshToken
    {
        return $this->findOneBy(['tokenDigest' => RefreshTokenEncryptor::digest($token)]);
    }

    /**
     * Finds a valid (non-expired) refresh token by its digest (used to follow a
     * rotation chain, where the predecessor stores its successor's digest).
     */
    public function findValidByDigest(string $digest): ?RefreshToken
    {
        $refreshToken = $this->findOneBy(['tokenDigest' => $digest]);

        if (null === $refreshToken || !$refreshToken->isValid()) {
            return null;
        }

        return $refreshToken;
    }

    /**
     * Deletes every refresh token of the given user, in one statement.
     */
    public function removeAllForUser(User $user): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(RefreshToken::class, 'rt')
            ->where('rt.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    private function issue(User $user): RefreshToken
    {
        $plain = bin2hex(random_bytes(64));
        $expiresAt = new \DateTimeImmutable(\sprintf('+%d days', self::TTL_DAYS));

        return RefreshToken::issue($user, $this->encryptor, $plain, $expiresAt);
    }
}
