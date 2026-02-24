<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
        'cache' => [
            'directory' => '%kernel.cache_dir%/pools',
            'default_redis_provider' => 'redis://localhost',
            'pools' => [
                'trip_state' => [
                    'adapter' => 'cache.adapter.redis',
                    'default_lifetime' => 30, // in minutes
                ],
                'osm' => [
                    'adapter' => 'cache.adapter.filesystem',
                ],
                'weather' => [
                    'adapter' => 'cache.adapter.filesystem',
                ],
            ],
        ],
    ]);
};
