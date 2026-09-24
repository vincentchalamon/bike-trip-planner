<?php

declare(strict_types=1);

namespace App\Security\OAuth;

/**
 * What the authorization endpoint knows about a pending consent, and what the person was
 * shown before deciding.
 *
 * The scopes are the ones LEAGUE RESOLVED, never the `scope` query parameter. They differ:
 * `scopes.default` cannot be configured empty, so a client that omits `scope` is granted
 * something anyway. Showing the request rather than the resolution would mean granting a
 * permission the screen never mentioned.
 */
final class ConsentRecord
{
    /**
     * @param list<string> $scopes      as resolved by league, not as requested
     * @param string       $continueUrl the authorization request to resume, rebuilt server-side
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly ?string $redirectUri,
        public readonly array $scopes,
        public readonly string $continueUrl,
        public ?bool $approved = null,
    ) {
    }
}
