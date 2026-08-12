<?php

declare(strict_types=1);

namespace App\ApiResource\Auth;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for `POST /auth/refresh`: the refresh token now travels in the
 * request body (OAuth-like), not a cookie. The web BFF (step 2) owns the cookie
 * and forwards the token here server-to-server.
 */
final class RefreshRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[SerializedName('refresh_token')]
        public string $refreshToken = '',
    ) {
    }
}
