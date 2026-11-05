<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\OAuthGrant;
use App\Repository\OAuthGrantRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessTokenInterface;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Treats a revoked refresh token presented again as a stolen one (OAuth 2.1 §4.3.1).
 *
 * League rotates on every refresh and revokes the token it consumed, but answers a replay of
 * that token with a plain `invalid_grant`: whoever refreshed first, the agent or a thief,
 * keeps the new pair. The first-party flow already revokes the family on reuse
 * (AuthRefreshProcessor); this brings the agent flow to the same answer by taking back the
 * whole (user, client) grant through {@see GrantRevoker}.
 *
 * `isRefreshTokenRevoked()` is the seat because league emits no event for this failure, and
 * it is only reached once the token decrypted, belongs to the calling client and has not
 * expired: a revoked flag there is a replay, not noise.
 *
 * A token revoked by the user from the account page leaves no live grant behind, so a replay
 * of it finds nothing to revoke. One revoked before a later re-authorisation of the same
 * client does find the new grant and revokes it: a stale credential resurfacing is the very
 * signal this guards on, and the user can let the application back in.
 */
#[AsDecorator(decorates: 'league.oauth2_server.repository.refresh_token')]
final readonly class ReplayRevokingRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private RefreshTokenRepositoryInterface $inner,
        private EntityManagerInterface $entityManager,
        private OAuthGrantRepository $grants,
        private GrantRevoker $revoker,
        private LoggerInterface $logger,
    ) {
    }

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return $this->inner->getNewRefreshToken();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $this->inner->persistNewRefreshToken($refreshTokenEntity);
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        $this->inner->revokeRefreshToken($tokenId);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        if (!$this->inner->isRefreshTokenRevoked($tokenId)) {
            return false;
        }

        $refreshToken = $this->entityManager->find(RefreshToken::class, $tokenId);
        $accessToken = $refreshToken?->getAccessToken();
        $userIdentifier = $accessToken?->getUserIdentifier();

        if (!$accessToken instanceof AccessTokenInterface || null === $userIdentifier) {
            return true;
        }

        $grant = $this->grants->findLiveByEmailAndClient($userIdentifier, $accessToken->getClient()->getIdentifier());
        if ($grant instanceof OAuthGrant) {
            $this->revoker->revoke($grant);
            $this->logger->warning('OAuth refresh token replayed; revoked the grant it belonged to.', [
                'grant' => $grant->getId()->toRfc4122(),
                'client' => $grant->getClient()->getIdentifier(),
            ]);
        }

        return true;
    }
}
