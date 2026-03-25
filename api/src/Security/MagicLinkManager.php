<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\MagicLink;
use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages magic link lifecycle: creation, verification, and consumption.
 *
 * Also handles refresh token creation and rotation.
 */
final readonly class MagicLinkManager
{
    private const int MAGIC_LINK_TTL_MINUTES = 30;

    private const int REFRESH_TOKEN_TTL_DAYS = 30;

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
        $magicLink = $this->entityManager->getRepository(MagicLink::class)->findOneBy([
            'token' => $token,
        ]);

        if (null === $magicLink || !$magicLink->isValid()) {
            return null;
        }

        $magicLink->consume();
        $this->entityManager->flush();

        return $magicLink->getUser();
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

        // Remove old token
        $this->entityManager->remove($existing);
        $this->entityManager->flush();

        // Create new one
        return $this->createRefreshToken($user);
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

    private function hasActiveLinkForUser(User $user): bool
    {
        $links = $this->entityManager->getRepository(MagicLink::class)->findBy([
            'user' => $user,
        ]);

        return array_any($links, fn ($link) => $link->isValid());
    }
}
