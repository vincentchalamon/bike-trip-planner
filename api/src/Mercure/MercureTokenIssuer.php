<?php

declare(strict_types=1);

namespace App\Mercure;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Issues Mercure subscriber JWTs scoped to a specific trip topic.
 *
 * The JWT is signed with the same HMAC secret used by the Mercure hub
 * for subscriber authentication. It contains a `mercure.subscribe` claim
 * limited to the trip's topic, ensuring users can only subscribe to
 * their own trip's SSE updates.
 */
final readonly class MercureTokenIssuer
{
    private const string COOKIE_NAME = 'mercureAuthorization';

    private const string COOKIE_PATH = '/.well-known/mercure';

    private const int TOKEN_TTL_SECONDS = 3600; // 1 hour

    private Configuration $jwtConfig;

    /** @param non-empty-string $mercureSecret */
    public function __construct(
        #[Autowire(env: 'MERCURE_JWT_SECRET')]
        string $mercureSecret,
    ) {
        $this->jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($mercureSecret),
        );
    }

    /**
     * Generates a subscriber JWT for the given trip topic.
     */
    public function generateSubscriberToken(string $tripId): string
    {
        $now = new \DateTimeImmutable();
        $topic = \sprintf('/trips/%s', $tripId);

        $token = $this->jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify(\sprintf('+%d seconds', self::TOKEN_TTL_SECONDS)))
            ->withClaim('mercure', ['subscribe' => [$topic]])
            ->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return $token->toString();
    }

    /**
     * Creates an HttpOnly cookie containing the subscriber JWT.
     *
     * The cookie path is scoped to `/.well-known/mercure` so it is only
     * sent with SSE subscription requests, not with regular API calls.
     */
    public function createSubscriberCookie(string $token): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue($token)
            ->withExpires(new \DateTimeImmutable(\sprintf('+%d seconds', self::TOKEN_TTL_SECONDS)))
            ->withPath(self::COOKIE_PATH)
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite('strict');
    }
}
