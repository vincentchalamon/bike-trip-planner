<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Shared cookie name constants for the authentication system.
 */
final class AuthCookies
{
    /**
     * `__Host-`: the browser only accepts it Secure, Path=/ and without a Domain, so no
     * sibling subdomain can plant or overwrite it. Written by the PWA's BFF, which must
     * use the same name.
     */
    public const string REFRESH_TOKEN = '__Host-refresh_token';
}
