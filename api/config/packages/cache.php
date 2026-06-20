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
                'cache.trip_chat' => [
                    'adapter' => 'cache.adapter.redis',
                    'default_lifetime' => 1800, // 30 minutes — short-lived dialogue history
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
                    'cache.trip_chat' => [
                        'adapter' => 'cache.adapter.array',
                    ],
                ],
            ],
        ]);
    }
};
