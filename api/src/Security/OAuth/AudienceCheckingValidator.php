<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\Token\UnsupportedHeaderFound;
use Lcobucci\JWT\UnencryptedToken;
use League\OAuth2\Server\AuthorizationValidators\BearerTokenValidator;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
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
 * ⚠ It EXTENDS `BearerTokenValidator` for one reason, and it is not reuse: `ResourceServer`
 * hands the public key over only to a validator that passes `instanceof BearerTokenValidator`
 * (ResourceServer.php:41-43). A decorator that merely implemented the interface would leave
 * the inner validator with no key at all, and every call would die on an uninitialised
 * property rather than fail closed. Nothing inherited is used: the parent constructor runs
 * to satisfy PHP, `setPublicKey()` is forwarded, and `validateAuthorization()` is replaced.
 *
 * Today this is belt to the braces of a dedicated signing key. It stops being redundant the
 * moment a second protected resource exists, which is the point at which forgetting it would
 * be expensive.
 */
#[AsDecorator(decorates: 'league.oauth2_server.bearer_token_validator')]
final class AudienceCheckingValidator extends BearerTokenValidator
{
    public function __construct(
        #[AutowireDecorated]
        private readonly BearerTokenValidator $inner,
        private readonly McpResource $resource,
        AccessTokenRepositoryInterface $accessTokenRepository,
    ) {
        parent::__construct($accessTokenRepository);
    }

    #[\Override]
    public function setPublicKey(CryptKeyInterface $key): void
    {
        $this->inner->setPublicKey($key);
    }

    #[\Override]
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
