<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Lcobucci\JWT\Token;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * An access token that says which resource it was issued for (RFC 8707, ADR-079).
 *
 * league stamps `aud` with the CLIENT's identifier and nothing else, and the audience cannot
 * be added from the outside: the bundle's extra-claims event goes through
 * `Lcobucci\JWT\Token\Builder::withClaim()`, which throws `RegisteredClaimGiven` on `aud`.
 * So the token is built here instead.
 *
 * ⚠ The ORDER of the two audiences is load-bearing. `BearerTokenValidator:138` reads
 * `$claims->get('aud')[0]` as the client id, and `permittedFor()` APPENDS — so putting the
 * resource first would silently make every request report the resource URI as its client.
 * The client stays first; the resource follows.
 *
 * The bundle's own entity also applies the extra claims its event collects. Nothing in this
 * application sets any, and adding one later means adding it here too — which is why this
 * class exists rather than a subclass: `League\Bundle\OAuth2ServerBundle\Entity\AccessToken`
 * is final.
 */
final class AudienceBoundAccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;

    public function __construct(
        private readonly string $audience,
    ) {
    }

    /**
     * Replaces the trait's own, which the trait's `toString()` calls: a method declared on
     * the class takes precedence over the one the trait brings in, and the trait's is then
     * not inserted at all. That is also why static analysis reads it as unused — it resolves
     * `$this->convertToJWT()` inside the trait to the trait's copy, which PHP has discarded.
     *
     * @phpstan-ignore method.unused
     */
    private function convertToJWT(): Token
    {
        $this->initJwtConfiguration();

        $client = $this->getClient()->getIdentifier();
        \assert('' !== $client && '' !== $this->audience);

        return $this->jwtConfiguration->builder()
            ->permittedFor($client, $this->audience)
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new \DateTimeImmutable())
            ->canOnlyBeUsedAfter(new \DateTimeImmutable())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getSubjectIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey());
    }
}
