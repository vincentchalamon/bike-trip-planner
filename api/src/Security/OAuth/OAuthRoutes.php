<?php

declare(strict_types=1);

namespace App\Security\OAuth;

/**
 * The names of the two OAuth endpoints declared in `config/routes/oauth2.php`, which the
 * listeners guarding them match on.
 */
final readonly class OAuthRoutes
{
    public const string AUTHORIZE = 'oauth2_authorize';

    public const string TOKEN = 'oauth2_token';
}
