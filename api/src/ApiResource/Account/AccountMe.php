<?php

declare(strict_types=1);

namespace App\ApiResource\Account;

/**
 * Output of `GET /users/me`: the authenticated user's own profile.
 *
 * JWT-authenticated (Bearer) counterpart of `GET /auth/session` — the latter is
 * cookie-only (web transport) and always resolves anonymous on mobile, which
 * carries the token as a Bearer header with no cookie. The current user is
 * resolved from the security token, never from a URL identifier (no IDOR).
 */
final class AccountMe
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $locale,
    ) {
    }
}
