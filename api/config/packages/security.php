<?php

declare(strict_types=1);

use App\Entity\User;
use App\Security\DeletedUserChecker;
use App\Security\OAuth\McpAuthenticationEntryPoint;
use App\Security\OAuth\RefreshCookieAuthenticator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('security', [
        'providers' => [
            'app_user_provider' => [
                'entity' => [
                    'class' => User::class,
                    'property' => 'email',
                ],
            ],
        ],
        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_(profiler|wdt)|css|images|js|docs)/',
                'security' => false,
            ],
            // Declared BEFORE `api`, whose pattern is `^/` and would otherwise swallow it.
            // The authorization endpoint is the one place a *browser* has to prove who it
            // is, and the only browser credential here is the BFF's refresh cookie
            // (ADR-047, ADR-079). Kept on its own firewall so that cookie never becomes a
            // credential for the rest of the API — and so it never shares a firewall with
            // a Bearer authenticator, which would race for the same header.
            'oauth_authorize' => [
                'pattern' => '^/oauth/authorize',
                'stateless' => true,
                'provider' => 'app_user_provider',
                'user_checker' => DeletedUserChecker::class,
                'custom_authenticators' => [RefreshCookieAuthenticator::class],
                // A person arriving from an agent's link gets sent to the login page, not
                // a JSON 401 — with no return target, so there is nothing to tamper with.
                'entry_point' => RefreshCookieAuthenticator::class,
            ],
            // Also before `api`, and for the same reason: `^/` would swallow it. The MCP
            // endpoint takes OAuth access tokens and nothing else — a PWA session JWT
            // presented here fails signature verification, because the two token systems
            // are told apart by their signing keys (ADR-079).
            'mcp' => [
                'pattern' => '^/mcp',
                'stateless' => true,
                'provider' => 'app_user_provider',
                // A deleted account's token stops working at use, not only at revocation.
                'user_checker' => DeletedUserChecker::class,
                'oauth2' => true,
                // The bundle's own entry point answers a bare `WWW-Authenticate: Bearer`,
                // which tells a conforming client nothing it can act on.
                'entry_point' => McpAuthenticationEntryPoint::class,
            ],
            'api' => [
                'pattern' => '^/',
                'stateless' => true,
                'provider' => 'app_user_provider',
                // Reject soft-deleted (anonymised) accounts on every authentication,
                // including the per-request JWT reload (see DeletedUserChecker).
                'user_checker' => DeletedUserChecker::class,
                'jwt' => [],
                // Object-level authz denials (TRIP_*) are surfaced as 404, not 403, to
                // avoid leaking trip existence by enumeration (ADR-038). Handled by
                // App\EventListener\HideForbiddenAsNotFoundListener on kernel.exception.
            ],
        ],
        'access_control' => [
            ['path' => '^/api/health(z)?$', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/docs', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/auth/(request-link|refresh|verify|session)$', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/access-requests(/verify)?$', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/auth/logout', 'roles' => 'IS_AUTHENTICATED_FULLY'],
            ['path' => '^/s/', 'roles' => 'PUBLIC_ACCESS'],
            // The token endpoint authenticates the CLIENT, with a code and a PKCE verifier,
            // not the user — there is no session or bearer to present. access_control is
            // global, so without this line the catch-all below would 401 every exchange.
            ['path' => '^/oauth/token$', 'roles' => 'PUBLIC_ACCESS'],
            // Discovery is what a client reads BEFORE it has any credential: the address of
            // the authorization server, and whether PKCE is supported at all. Closed, the
            // flow cannot start.
            ['path' => '^/\.well-known/oauth-(authorization-server|protected-resource)', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/', 'roles' => 'IS_AUTHENTICATED_FULLY'],
        ],
    ]);
};
