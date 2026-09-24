<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Mints tokens that name the resource they are for (ADR-079).
 *
 * Only the minting changes. Persistence, revocation and the revoked check are the bundle's,
 * untouched — this decorator exists because `getNewToken()` is the single point where the
 * entity class is chosen, and the bundle's is final.
 */
#[AsDecorator(decorates: 'league.oauth2_server.repository.access_token')]
final readonly class AudienceBoundAccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private AccessTokenRepositoryInterface $inner,
        private McpResource $resource,
    ) {
    }

    /**
     * @param ScopeEntityInterface[] $scopes
     */
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $accessToken = new AudienceBoundAccessToken($this->resource->canonicalUri());
        $accessToken->setClient($clientEntity);

        if (null !== $userIdentifier && '' !== $userIdentifier) {
            $accessToken->setUserIdentifier($userIdentifier);
        }

        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }

        return $accessToken;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->inner->persistNewAccessToken($accessTokenEntity);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->inner->revokeAccessToken($tokenId);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return $this->inner->isAccessTokenRevoked($tokenId);
    }
}
