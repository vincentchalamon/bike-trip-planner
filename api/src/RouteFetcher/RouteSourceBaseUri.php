<?php

declare(strict_types=1);

namespace App\RouteFetcher;

/**
 * Where each route source lives, declared once.
 *
 * Read by two configurations that must agree: `framework.php`, which scopes each client to its
 * base URI, and `services.php`, which wraps each client in NoPrivateNetworkHttpClient. That
 * wrapper resolves a relative path against its own base URI before delegating, so it needs the
 * same one — and two literals would be two places for them to drift apart silently.
 */
final class RouteSourceBaseUri
{
    public const string KOMOOT = 'https://www.komoot.com';

    public const string STRAVA = 'https://www.strava.com';

    public const string RIDEWITHGPS = 'https://ridewithgps.com';

    /** Scoped client id => base URI. */
    public const array BY_CLIENT = [
        'komoot.client' => self::KOMOOT,
        'strava.client' => self::STRAVA,
        'ridewithgps.client' => self::RIDEWITHGPS,
    ];
}
