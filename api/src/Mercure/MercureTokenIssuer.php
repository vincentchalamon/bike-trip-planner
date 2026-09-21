<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;

/**
 * Issues Mercure subscriber access tokens scoped to a specific trip topic.
 *
 * The token shape comes from the hub's own factory (configured by
 * MercureBundle with `protocol_version: 1.0`), so it is an RFC 9068 access
 * token — `typ: at+jwt`, `iss`/`aud`/`sub`/`client_id`, and an
 * `authorization_details` array — signed with the same secret the hub
 * verifies. Building it here by hand would duplicate that contract and could
 * drift from the tokens the bundle mints when publishing.
 */
final readonly class MercureTokenIssuer
{
    private const string COOKIE_PATH = '/.well-known/mercure';

    private const int TOKEN_TTL_SECONDS = 3600; // 1 hour

    private const int SUBSCRIPTIONS_TOKEN_TTL_SECONDS = 60;

    public function __construct(
        private HubInterface $hub,
    ) {
    }

    /**
     * Generates a subscriber token for the given trip topic.
     */
    public function generateSubscriberToken(string $tripId): string
    {
        return $this->create(
            [new Grant([Grant::ACTION_SUBSCRIBE], [$this->topic($tripId)])],
            self::TOKEN_TTL_SECONDS,
        );
    }

    /**
     * Generates a token authorising a read of the hub's subscription API for a trip
     * topic (#1124). Server-side only (never handed to a client): it lets the
     * backend ask the hub whether anyone is currently subscribed to the trip's SSE
     * stream. The grant must cover both the subscription-API path and the trip
     * topic itself.
     *
     * The API path carries the match type, the percent-encoded topic and the
     * subscriber id as separate segments, so it is matched with a URL Pattern
     * wildcard: a `:param` placeholder would stop at the first `/`.
     */
    public function generateSubscriptionsToken(string $tripId): string
    {
        return $this->create(
            [new Grant([Grant::ACTION_SUBSCRIBE], [
                'exact' => [$this->topic($tripId)],
                'urlpattern' => ['/.well-known/mercure/subscriptions/*'],
            ])],
            self::SUBSCRIPTIONS_TOKEN_TTL_SECONDS,
        );
    }

    /**
     * Creates an HttpOnly cookie containing the subscriber token.
     *
     * The cookie path is scoped to `/.well-known/mercure` so it is only
     * sent with SSE subscription requests, not with regular API calls. Under
     * protocol 1.0 its name carries the `__Secure-` prefix, which browsers only
     * accept on a secure origin — hence `withSecure(true)` is load-bearing, not
     * defensive.
     */
    public function createSubscriberCookie(string $token): Cookie
    {
        return Cookie::create($this->hub->getCookieName())
            ->withValue($token)
            ->withExpires(new \DateTimeImmutable(\sprintf('+%d seconds', self::TOKEN_TTL_SECONDS)))
            ->withPath(self::COOKIE_PATH)
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    /** @param list<Grant> $grants */
    private function create(array $grants, int $ttl): string
    {
        $factory = $this->hub->getFactory();

        if (!$factory instanceof TokenFactoryInterface) {
            throw new \LogicException('The default Mercure hub has no token factory; check "mercure.hubs.default.jwt" in config/packages/mercure.php.');
        }

        return $factory->create($grants, [
            'exp' => new \DateTimeImmutable(\sprintf('+%d seconds', $ttl)),
        ]);
    }

    private function topic(string $tripId): string
    {
        return \sprintf('/trips/%s', $tripId);
    }
}
