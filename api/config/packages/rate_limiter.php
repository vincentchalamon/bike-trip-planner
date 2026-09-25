<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
        'rate_limiter' => [
            'magic_link_email' => [
                'policy' => 'sliding_window',
                'limit' => 3,
                'interval' => '900 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            'magic_link_ip' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '900 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            'email_change_user' => [
                'policy' => 'sliding_window',
                'limit' => 3,
                'interval' => '900 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            'email_change_ip' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '900 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            'trip_create' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // GPX upload is a second trip-creation entry point: throttle it like
            // trip_create so it cannot bypass the create limiter (SEC-006).
            'gpx_upload' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // Trip duplication clones DB rows + Redis blobs; cap per user (SEC-009).
            'trip_duplicate' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // Recompute re-dispatches the full enrichment pipeline onto the shared
            // workers; cap per user so it cannot be scripted faster than they drain (SEC-010).
            'trip_recompute' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // Geocoding proxies to the public Nominatim instance (1 req/s policy,
            // IP bans for bulk use); cap per user to protect the shared app IP
            // (geocode rate-limit — 2026-07 security audit).
            'geocode' => [
                'policy' => 'sliding_window',
                'limit' => 30,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            'access_request_ip' => [
                'policy' => 'sliding_window',
                'limit' => 3,
                'interval' => '3600 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // In-ride nearby-POI search (#934): a read-only PostGIS lookup a rider
            // can fire repeatedly while moving.
            'nearby_pois' => [
                'policy' => 'sliding_window',
                'limit' => 30,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // The five anonymous /s/{shortCode}* operations, keyed on the caller's IP — there
            // is no account to key on. /s/{shortCode} runs the heaviest read in the API and
            // had no limiter of any kind; a share link is public by destination, so the cost
            // of holding one is the cost of everyone the owner sent it to. Generous enough
            // that a page with a map and a GPX download never trips it.
            'shared_trip' => [
                'policy' => 'sliding_window',
                'limit' => 60,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // GDPR portability is a once-in-a-while gesture, and the query behind it walks
            // every trip the user owns with no upper bound. Per hour, not per minute.
            'account_export' => [
                'policy' => 'sliding_window',
                'limit' => 3,
                'interval' => '3600 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // Resolving a Client ID Metadata Document is an outbound request to a host the
            // caller named (ADR-079). Two budgets, both needed and for different victims:
            // per user, because each distinct client id is a row in `oauth2_client` and one
            // account could otherwise fill the table; per host, because the server on the
            // other end is a third party we are making requests to.
            'oauth_client_metadata_user' => [
                'policy' => 'sliding_window',
                'limit' => 20,
                'interval' => '3600 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            'oauth_client_metadata_host' => [
                'policy' => 'sliding_window',
                'limit' => 60,
                'interval' => '3600 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // The code exchange is anonymous by construction: it authenticates the client
            // with a code and a PKCE verifier, so there is no account to key on. Generous,
            // because a legitimate agent refreshes on its own schedule and several may share
            // one address behind a NAT.
            'oauth_token' => [
                'policy' => 'sliding_window',
                'limit' => 60,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // The authorization endpoint is crossed twice per grant and a person is reading a
            // screen in between, so this bounds a loop rather than ordinary use.
            'oauth_authorize' => [
                'policy' => 'sliding_window',
                'limit' => 30,
                'interval' => '300 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // The two MCP budgets, counted per call by App\Security\OAuth\McpCallBudget and keyed
            // on the user and the OAuth client. Sized for the loop the design prescribes: one
            // ordinary task is a write, ten to fifteen `get_trip` polls while the days are
            // computed, then a `get_stage` per day — twenty to thirty reads in one minute. Kept
            // above `geocode` (30/min), which already bounds `search_places` on its own.
            'mcp_tool_call' => [
                'policy' => 'sliding_window',
                'limit' => 60,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // On top, for the calls that write. Above `trip_create` (10/min), which keeps bounding
            // creation — and the third-party fetches behind it — by itself.
            'mcp_mutation' => [
                'policy' => 'sliding_window',
                'limit' => 20,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // A per-address ceiling on /mcp, consumed before the firewall so that callers with no
            // valid token are bounded too (App\EventListener\McpEnvelopeThrottleListener). Per
            // HTTP request and generous: several agents may share an address behind a NAT, and
            // the fine-grained budget is the per-call one.
            'mcp_envelope' => [
                'policy' => 'sliding_window',
                'limit' => 300,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            'health_liveness' => [
                'policy' => 'sliding_window',
                'limit' => 60,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            'health_readiness' => [
                'policy' => 'sliding_window',
                'limit' => 20,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
        ],
        'cache' => [
            'pools' => [
                'cache.rate_limiter' => [
                    'adapter' => 'cache.adapter.redis',
                ],
            ],
        ],
    ]);

    if ('test' === $containerConfigurator->env()) {
        $containerConfigurator->extension('framework', [
            'cache' => [
                'pools' => [
                    'cache.rate_limiter' => [
                        'adapter' => 'cache.adapter.array',
                    ],
                ],
            ],
        ]);
    }
};
