<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages refresh token lifecycle: creation, rotation, and revocation.
 */
final readonly class RefreshTokenManager
{
    private const int REFRESH_TOKEN_TTL_DAYS = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Creates a new refresh token for the given user.
     */
    public function createRefreshToken(User $user): RefreshToken
    {
        $token = bin2hex(random_bytes(64));
        $expiresAt = new \DateTimeImmutable(\sprintf('+%d days', self::REFRESH_TOKEN_TTL_DAYS));

        $refreshToken = new RefreshToken($user, $token, $expiresAt);
        $this->entityManager->persist($refreshToken);
        $this->entityManager->flush();

        return $refreshToken;
    }

    /**
     * Validates a refresh token and rotates it (deletes old, creates new).
     *
     * Returns null if the token is invalid or expired.
     */
    public function rotateRefreshToken(string $token): ?RefreshToken
    {
        $existing = $this->entityManager->getRepository(RefreshToken::class)->findOneBy([
            'token' => $token,
        ]);

        if (null === $existing || !$existing->isValid()) {
            return null;
        }

        $user = $existing->getUser();
        $this->entityManager->remove($existing);

        $newToken = bin2hex(random_bytes(64));
        $expiresAt = new \DateTimeImmutable(\sprintf('+%d days', self::REFRESH_TOKEN_TTL_DAYS));
        $refreshToken = new RefreshToken($user, $newToken, $expiresAt);
        $this->entityManager->persist($refreshToken);

        // Single atomic flush: old token removed and new token created together
        $this->entityManager->flush();

        return $refreshToken;
    }

    /**
     * Revokes all refresh tokens for the given user (logout from all devices).
     */
    public function revokeAllRefreshTokens(User $user): void
    {
        $tokens = $this->entityManager->getRepository(RefreshToken::class)->findBy([
            'user' => $user,
        ]);

        foreach ($tokens as $token) {
            $this->entityManager->remove($token);
        }

        $this->entityManager->flush();
    }
}
