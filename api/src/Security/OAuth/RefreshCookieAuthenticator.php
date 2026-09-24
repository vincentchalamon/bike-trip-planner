<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Symfony\Component\HttpFoundation\RedirectResponse;
use App\Entity\RefreshToken;
use App\Repository\RefreshTokenRepository;
use App\Security\AuthCookies;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Identifies the person behind `/oauth/authorize`, from the cookie the BFF already sets.
 *
 * The bundle requires an authenticated user on its authorization endpoint — it does not
 * redirect to a login, it throws (`AuthorizationRequestResolveEventFactoryTrait`: "A logged
 * in user is required to resolve the authorization request", i.e. a 500). But the API has
 * no session (`framework.session: false`) and the only firewall is stateless with a Bearer
 * authenticator, and a browser following an agent's authorization link carries no Bearer.
 *
 * The one browser credential this deployment has is the httpOnly `refresh_token` cookie the
 * Next.js BFF sets (ADR-047). It is read here exactly as {@see \App\State\Auth\AuthSessionProvider}
 * reads it for `GET /auth/session`: looked up, never rotated, never re-issued, no Set-Cookie.
 * The cookie is SameSite=Lax, so it travels on the top-level navigation that brings the user
 * here and on nothing else.
 *
 * Scope is the point. This authenticator is mounted on `^/oauth/authorize` and nowhere else:
 * the refresh cookie must not become a general-purpose API credential, and it must never sit
 * on the same firewall as the Bearer authenticators — `supports()` on those matches any
 * `Authorization: Bearer`, so two of them on one firewall would race for the same header.
 */
final class RefreshCookieAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly LoginRedirectEntryPoint $entryPoint,
    ) {
    }

    public function supports(Request $request): bool
    {
        // Not `null`: a missing cookie must fall through to the entry point (a redirect to
        // the login page), not be reported as a failed authentication.
        return '' !== (string) $request->cookies->get(AuthCookies::REFRESH_TOKEN, '');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $token = (string) $request->cookies->get(AuthCookies::REFRESH_TOKEN, '');

        $refreshToken = $this->refreshTokens->findValidByToken($token);
        if (!$refreshToken instanceof RefreshToken) {
            throw new CustomUserMessageAuthenticationException('Invalid credentials.');
        }

        $user = $refreshToken->getUser();

        // The loader hands back the user the token points at instead of looking the
        // identifier up again. A second lookup by email would be a second source of truth
        // for who this cookie belongs to, and `anonymize()` rewrites that email.
        // DeletedUserChecker still runs on this user and rejects a deleted account.
        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn (): UserInterface => $user),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * An expired or unknown cookie is the same situation as no cookie at all, from the
     * point of view of someone standing in front of a browser: send them to the login page
     * rather than to an error body they cannot act on.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): RedirectResponse
    {
        return $this->entryPoint->start($request, $exception);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): RedirectResponse
    {
        return $this->entryPoint->start($request, $authException);
    }
}
