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
            'trip_chat' => [
                'policy' => 'sliding_window',
                'limit' => 20,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // AI route generation is heavier than chat (LLM + geocoding + Valhalla,
            // possibly a corrective re-prompt) and runs on the user's own quota,
            // so it is throttled more tightly.
            'ai_generate' => [
                'policy' => 'sliding_window',
                'limit' => 5,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            // Stateless trip-brief chat (ADR-045): one LLM call per user turn on
            // the rider's own quota, like the loaded-trip chat.
            'ai_chat' => [
                'policy' => 'sliding_window',
                'limit' => 20,
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
