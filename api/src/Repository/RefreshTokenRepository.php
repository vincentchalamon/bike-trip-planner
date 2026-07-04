<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Security\RefreshTokenEncryptor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
final class RefreshTokenRepository extends ServiceEntityRepository
{
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
     *
     * Persists the entity but does NOT flush — the caller is responsible for flushing.
     */
    public function createForUser(User $user): RefreshToken
    {
        $plain = bin2hex(random_bytes(64));
        $expiresAt = new \DateTimeImmutable(\sprintf('+%d days', self::TTL_DAYS));

        $refreshToken = RefreshToken::issue($user, $this->encryptor, $plain, $expiresAt);
        $this->getEntityManager()->persist($refreshToken);

        return $refreshToken;
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
     * Marks all refresh tokens for the given user for removal.
     *
     * Does NOT flush — the caller is responsible for flushing.
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
}
