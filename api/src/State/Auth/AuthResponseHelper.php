<?php

declare(strict_types=1);

namespace App\State\Auth;

use App\Security\AuthCookies;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shared helpers for auth processors (Capacitor detection, refresh token cookie).
 */
trait AuthResponseHelper
{
    abstract private function getRequestStack(): RequestStack;

    private function isCapacitorRequest(): bool
    {
        $request = $this->getRequestStack()->getCurrentRequest();
        $origin = $request?->headers->get('Origin', '') ?? '';

        return str_starts_with($origin, 'capacitor://');
    }

    private function setRefreshTokenCookie(JsonResponse $response, string $token, \DateTimeImmutable $expiresAt): void
    {
        $cookie = Cookie::create(AuthCookies::REFRESH_TOKEN)
            ->withValue($token)
            ->withExpires($expiresAt)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite('strict');

        $response->headers->setCookie($cookie);
    }
}
