<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
        'secret' => '%env(APP_SECRET)%',
        'http_method_override' => false,
        'handle_all_throwables' => true,
        'trusted_proxies' => '%env(TRUSTED_PROXIES)%',
        'trusted_hosts' => '%env(TRUSTED_HOSTS)%',
        'trusted_headers' => [
            'x-forwarded-for',
            'x-forwarded-proto',
        ],
        'session' => false,
        'php_errors' => [
            'log' => true,
        ],
        'http_client' => [
            // SSRF control (ADR-011): each outbound client is host-locked (base_uri
            // or a scope regex), and the route fetchers only ever interpolate a
            // numeric id captured by an anchored regex into a fixed relative path,
            // so a user URL never sets the host. The route clients keep a small
            // max_redirects for the third parties' legitimate locale redirects
            // (host-locked, so a redirect to an internal host requires the third
            // party's own origin to be compromised — low residual risk); clients
            // that never legitimately redirect use max_redirects: 0.
            'scoped_clients' => [
                'komoot.client' => [
                    'base_uri' => 'https://www.komoot.com',
                    'max_redirects' => 2,
                    'timeout' => 10,
                    // On-demand route fetch (Tier 3): retry transient failures
                    // (429/5xx + transport errors) with back-off; permanent client
                    // errors (404/403) are not retried, so they fail fast.
                    'retry_failed' => [
                        'max_retries' => 2,
                    ],
                    'headers' => [
                        'Accept' => 'text/html',
                        'User-Agent' => 'BikeTripPlanner/1.0',
                    ],
                ],
                'strava.client' => [
                    'base_uri' => 'https://www.strava.com',
                    'max_redirects' => 2,
                    'timeout' => 10,
                    'retry_failed' => [
                        'max_retries' => 2,
                    ],
                    'headers' => [
                        'Accept' => 'application/gpx+xml',
                        'User-Agent' => 'BikeTripPlanner/1.0',
                    ],
                ],
                'ridewithgps.client' => [
                    'base_uri' => 'https://ridewithgps.com',
                    'max_redirects' => 2,
                    'timeout' => 10,
                    'retry_failed' => [
                        'max_retries' => 2,
                    ],
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'BikeTripPlanner/1.0',
                    ],
                ],
                'open_meteo.client' => [
                    'base_uri' => 'https://api.open-meteo.com',
                    'timeout' => 10,
                    'max_redirects' => 2,
                    // Live, on-demand source (Tier 3): retry transient failures
                    // (429/5xx + transport errors) with exponential back-off rather
                    // than dropping the forecast on the first hiccup.
                    'retry_failed' => [
                        'max_retries' => 3,
                    ],
                ],
                'routing.client' => [
                    // Host-locked to the Valhalla service. Configurable so the
                    // shared Valhalla resource (ADR-061) can live outside the app
                    // stack; the default keeps the in-stack service name for dev.
                    'base_uri' => '%env(VALHALLA_BASE_URI)%',
                    'timeout' => 5,
                    // Direct internal Valhalla API — never redirects; refuse to follow
                    // any 3xx so a compromised response can't pivot to another internal
                    // host (SEC-007), consistent with the invariant documented above.
                    'max_redirects' => 0,
                ],
                'nominatim.client' => [
                    'base_uri' => 'https://nominatim.openstreetmap.org',
                    'timeout' => 10,
                    // Nominatim's /search and /reverse are direct API endpoints that
                    // never legitimately redirect, so refuse to follow any 3xx: base_uri
                    // does not constrain a redirect's host, and following one could reach
                    // an internal target (SSRF, SEC-007).
                    'max_redirects' => 0,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'BikeTripPlanner/1.0',
                    ],
                ],
                'mercure.health.client' => [
                    'base_uri' => '%env(MERCURE_URL)%',
                    'max_redirects' => 0,
                    'timeout' => 2,
                ],
                // Google OAuth2 token endpoint for the FCM service-account
                // JWT-bearer exchange (epic #1051). Host-locked; the /token endpoint
                // never legitimately redirects, so refuse any 3xx (SEC-007).
                'google_oauth.client' => [
                    'base_uri' => 'https://oauth2.googleapis.com',
                    'max_redirects' => 0,
                    'timeout' => 10,
                    'retry_failed' => [
                        'max_retries' => 2,
                    ],
                ],
                // FCM HTTP v1 send endpoint (epic #1051). Host-locked; the project
                // id folded into the path is a trusted server-side value, never a
                // user URL, and the endpoint never redirects (SEC-007). No
                // retry_failed: messages:send is NOT idempotent and the default
                // retry set includes 0 (drop/timeout) — precisely the case where
                // FCM may already have delivered — so a retry risks a duplicate
                // push. Messenger's own retry on the worker covers transient loss.
                'fcm.client' => [
                    'base_uri' => 'https://fcm.googleapis.com',
                    'max_redirects' => 0,
                    'timeout' => 10,
                ],
            ],
        ],
    ]);
    if ('test' === $containerConfigurator->env()) {
        $containerConfigurator->extension('framework', [
            'test' => true,
        ]);
    }
};
