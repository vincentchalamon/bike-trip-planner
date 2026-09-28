<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Psr\Clock\ClockInterface;

/**
 * Mints tokens that name the resource they are for (ADR-079), and records who was let in.
 *
 * The minting is why this decorator exists: `getNewToken()` is the single point where the
 * entity class is chosen, and the bundle's is final. Revocation and the revoked check stay the
 * bundle's, untouched.
 *
 * Persistence is no longer quite untouched, and the reason is worth the line: issuing a token
 * is the only moment that proves an application was really let in, so it is where the grant a
 * user will later see gets written ({@see GrantRecorder}, which says why the obvious seats are
 * traps). What is written is a note beside the token, never a condition of it — a failure
 * there must never cost an agent its connection.
 */
#[AsDecorator(decorates: 'league.oauth2_server.repository.access_token')]
final readonly class AudienceBoundAccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private AccessTokenRepositoryInterface $inner,
        private McpResource $resource,
        private GrantRecorder $grants,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param ScopeEntityInterface[] $scopes
     */
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $accessToken = new AudienceBoundAccessToken($this->resource->canonicalUri(), $this->clock);
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

        $userIdentifier = $accessTokenEntity->getUserIdentifier();

        // Client credentials would carry no user. This server does not enable that grant, but a
        // grant row without someone to show it to is meaningless either way.
        if (null === $userIdentifier || '' === $userIdentifier) {
            return;
        }

        try {
            $this->grants->record(
                $userIdentifier,
                $accessTokenEntity->getClient()->getIdentifier(),
                array_values(array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $accessTokenEntity->getScopes())),
            );
        } catch (\Throwable $throwable) {
            // The token is already persisted and about to be served. Losing the note is a wrong
            // line on a screen; losing the token is an agent that cannot connect.
            $this->logger->error('Could not record the OAuth grant for an issued token.', ['exception' => $throwable]);
        }
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
