<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\Token\UnsupportedHeaderFound;
use Lcobucci\JWT\UnencryptedToken;
use League\OAuth2\Server\AuthorizationValidators\AuthorizationValidatorInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Refuses a token that was not issued for this resource (RFC 8707, ADR-079).
 *
 * The MCP specification requires a server to accept only tokens meant for itself, and
 * league's validator does not look at the audience at all — it reads `aud[0]` as a client
 * identifier and moves on.
 *
 * The inner validator runs first and decides everything that matters: signature, expiry,
 * revocation. If it returns, the string is authentic. Re-parsing it afterwards to READ one
 * claim is therefore not a second security decision — nothing is re-decided, and a claim
 * that cannot be read can only cause a refusal.
 *
 * Today there is exactly one resource and one issuer, so this is belt to the braces of a
 * dedicated signing key. It stops being redundant the moment a second protected resource
 * exists, which is the point at which forgetting it would be expensive.
 */
#[AsDecorator(decorates: 'league.oauth2_server.bearer_token_validator')]
final readonly class AudienceCheckingValidator implements AuthorizationValidatorInterface
{
    public function __construct(
        #[AutowireDecorated]
        private AuthorizationValidatorInterface $inner,
        private McpResource $resource,
    ) {
    }

    public function validateAuthorization(ServerRequestInterface $request): ServerRequestInterface
    {
        $validated = $this->inner->validateAuthorization($request);

        $header = $request->getHeader('authorization');
        $jwt = trim((string) preg_replace('/^\s*Bearer\s/i', '', $header[0] ?? ''));

        if ('' === $jwt) {
            // Unreachable: the inner validator refuses an empty bearer before this point.
            throw OAuthServerException::accessDenied('The access token could not be read.');
        }

        try {
            $token = new Parser(new JoseEncoder())->parse($jwt);
        } catch (\InvalidArgumentException|\RuntimeException|UnsupportedHeaderFound) {
            throw OAuthServerException::accessDenied('The access token could not be read.');
        }

        if (!$token instanceof UnencryptedToken
            || !$token->claims()->has(RegisteredClaims::AUDIENCE)
            || !\in_array($this->resource->canonicalUri(), (array) $token->claims()->get(RegisteredClaims::AUDIENCE), true)
        ) {
            throw OAuthServerException::accessDenied('The access token was not issued for this resource.');
        }

        return $validated;
    }
}
