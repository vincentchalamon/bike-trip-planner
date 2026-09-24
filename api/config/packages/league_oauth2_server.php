<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The OAuth 2.1 authorization server that fronts the MCP endpoint (ADR-079).
 *
 * Written as PHP because api/config has no YAML; the Flex recipe writes two .yaml files and
 * points the signing keys at Lexik's keypair, both of which are undone here.
 *
 * Every grant this server does not need is closed explicitly rather than left at its default.
 * `enable_client_credentials_grant` in particular defaults to TRUE: a token issued by it
 * carries no user, and OAuth2Authenticator then hands the firewall a ClientCredentialsUser
 * that is not an App\Entity\User — a shape no voter in this application was written for.
 */
return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('league_oauth2_server', [
        'authorization_server' => [
            // Its own keypair, never Lexik's: that difference is what makes a PWA session
            // token fail signature verification on /mcp, and an agent token fail it on the
            // REST API. The Flex recipe points both of these at config/jwt — at Lexik's own
            // keys — so the separation is asserted rather than assumed.
            'private_key' => '%env(resolve:OAUTH_PRIVATE_KEY)%',
            'private_key_passphrase' => '%env(OAUTH_PASSPHRASE)%',
            'encryption_key' => '%env(OAUTH_ENCRYPTION_KEY)%',

            // The only two grants an MCP client uses.
            'enable_auth_code_grant' => true,
            'enable_refresh_token_grant' => true,

            'enable_client_credentials_grant' => false,
            'enable_device_code_grant' => false,
            'enable_password_grant' => false,
            'enable_implicit_grant' => false,

            // Both default to true; restated because the whole security posture rests on them.
            'require_code_challenge_for_public_clients' => true,
            'revoke_refresh_tokens' => true,
            // And this one, because without it nothing is revocable at all.
            'persist_access_token' => true,

            // Shorter than the bundle's PT1H: an access token is the credential an agent
            // carries around, and the MCP specification asks for short-lived ones.
            'access_token_ttl' => 'PT15M',
            // The authorization code is the interceptable link of the flow and is redeemed
            // by a machine, immediately. The bundle allows PT10M; OAuth 2.1 caps it at ten
            // minutes, which is an upper bound, not a target.
            'auth_code_ttl' => 'PT2M',
            // An agent is episodic: it disappears between conversations and comes back. A
            // month is how long "comes back" is worth supporting without a new consent.
            'refresh_token_ttl' => 'P1M',
        ],
        'resource_server' => [
            'public_key' => '%env(resolve:OAUTH_PUBLIC_KEY)%',
        ],
        'scopes' => [
            'available' => ['trips:read', 'trips:write'],
            // `default` is isRequired()->cannotBeEmpty(): there is no way to say "no scope
            // unless one is asked for". A client that omits `scope` therefore always gets
            // something, and the least of the two is the read one. The consent screen shows
            // the scopes league resolved, not the ones the request asked for, precisely so
            // this default is never granted silently.
            'default' => ['trips:read'],
        ],
        'persistence' => [
            'doctrine' => null,
        ],
        'client' => [
            'allow_plaintext_secrets' => false,
        ],
    ]);
};
