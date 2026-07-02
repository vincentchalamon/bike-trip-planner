<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

/**
 * Read-only auth session state (recette #649 #8, ADR-047).
 *
 * Output of `GET /auth/session`: reports whether the refresh_token cookie maps
 * to a live, non-deleted user, WITHOUT rotating anything. Shape is compatible
 * with the frontend `AuthUser { id, email }`.
 */
final class AuthSession
{
    public function __construct(
        public bool $authenticated = false,
        public ?string $userId = null,
        public ?string $email = null,
    ) {
    }
}
