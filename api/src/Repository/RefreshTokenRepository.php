<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
final class RefreshTokenRepository extends ServiceEntityRepository
{
    private const int TTL_DAYS = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    /**
     * Creates a new refresh token for the given user.
     *
     * Persists the entity but does NOT flush — the caller is responsible for flushing.
     */
    public function createForUser(User $user): RefreshToken
    {
        $token = bin2hex(random_bytes(64));
        $expiresAt = new \DateTimeImmutable(\sprintf('+%d days', self::TTL_DAYS));

        $refreshToken = new RefreshToken($user, $token, $expiresAt);
        $this->getEntityManager()->persist($refreshToken);

        return $refreshToken;
    }

    /**
     * Finds a valid (non-expired) refresh token by its token string.
     */
    public function findValidByToken(string $token): ?RefreshToken
    {
        $refreshToken = $this->findOneBy(['token' => $token]);

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
        $tokens = $this->findBy(['user' => $user]);

        foreach ($tokens as $token) {
            $this->getEntityManager()->remove($token);
        }
    }
}
