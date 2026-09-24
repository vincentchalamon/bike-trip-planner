<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
        'cache' => [
            'default_redis_provider' => '%env(REDIS_URL)%',
            // Pin the framework default pool to Redis: doctrine.result_cache_pool
            // is backed by cache.app, whose default adapter is the filesystem one.
            // Under read_only (no var volume, see ADR-037 / #728) a filesystem write
            // would fail, so every cache write must go to Redis.
            'app' => 'cache.adapter.redis',
            'pools' => [
                'cache.trip_state' => [
                    'adapter' => 'cache.adapter.redis',
                    'default_lifetime' => 1800, // 30 minutes
                ],
                'cache.osm' => [
                    'adapter' => 'cache.adapter.redis',
                    'default_lifetime' => 86400, // 24 hours
                ],
                'cache.weather' => [
                    'adapter' => 'cache.adapter.redis',
                    'default_lifetime' => 10800, // 3 hours
                ],
                'cache.route_fetch' => [
                    'adapter' => 'cache.adapter.redis',
                    'default_lifetime' => 86400, // 24 hours
                ],
                'cache.routing' => [
                    'adapter' => 'cache.adapter.redis',
                    'default_lifetime' => 86400, // 24 hours
                ],
                // A consent decision, between the moment the browser is sent to the
                // consent screen and the moment it comes back (ADR-079). Transient by
                // nature and single-use; long enough for someone to read the screen.
                'cache.oauth_consent' => [
                    'adapter' => 'cache.adapter.redis',
                    'default_lifetime' => 600, // 10 minutes
                ],
            ],
        ],
    ]);
    if ('test' === $containerConfigurator->env()) {
        $containerConfigurator->extension('framework', [
            'cache' => [
                'pools' => [
                    'cache.trip_state' => [
                        'adapter' => 'cache.adapter.array',
                    ],
                    'cache.osm' => [
                        'adapter' => 'cache.adapter.array',
                    ],
                    'cache.weather' => [
                        'adapter' => 'cache.adapter.array',
                    ],
                    'cache.route_fetch' => [
                        'adapter' => 'cache.adapter.array',
                    ],
                    'cache.routing' => [
                        'adapter' => 'cache.adapter.array',
                    ],
                    // cache.oauth_consent stays on Redis under test, deliberately. The
                    // consent is written by one request and read by the next, and the test
                    // environment resets every service implementing ResetInterface after
                    // each request — an array adapter forgets it in between, whatever the
                    // client does about rebooting. Pools namespace themselves per container
                    // build, so these keys do not collide with the dev stack's.
                ],
            ],
        ]);
    }
};
