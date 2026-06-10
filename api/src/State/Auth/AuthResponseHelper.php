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
        // SameSite=Lax (not Strict) so the cookie is sent on top-level cross-site
        // navigations (e.g. following a magic link from an email client, or a
        // bookmark). The web server reads it during SSR to render the dashboard
        // on the first paint instead of flashing the landing (#649). Lax still
        // withholds the cookie from cross-site sub-requests / POSTs, so the
        // refresh endpoint stays CSRF-safe.
        $cookie = Cookie::create(AuthCookies::REFRESH_TOKEN)
            ->withValue($token)
            ->withExpires($expiresAt)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite('lax');

        $response->headers->setCookie($cookie);
    }
}
