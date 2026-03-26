<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\ApiResource\Auth\Auth;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared helpers for auth processors: Capacitor detection and refresh token cookie.
 */
final class AuthResponseHelper
{
    public static function isCapacitorRequest(?Request $request): bool
    {
        $origin = $request?->headers->get('Origin', '') ?? '';

        return str_starts_with($origin, 'capacitor://');
    }

    public static function createRefreshTokenCookie(string $token, \DateTimeImmutable $expiresAt): Cookie
    {
        return Cookie::create(Auth::REFRESH_TOKEN_COOKIE)
            ->withValue($token)
            ->withExpires($expiresAt)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite('strict');
    }
}
