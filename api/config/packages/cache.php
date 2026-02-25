<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
        'cache' => [
            'default_redis_provider' => '%env(REDIS_URL)%',
            'pools' => [
                'cache.trip_state' => [
                    'adapter' => 'cache.adapter.redis',
                    'default_lifetime' => 1800, // 30 minutes
                ],
                'cache.osm' => [
                    'adapter' => 'cache.adapter.filesystem',
                    'default_lifetime' => 86400, // 24 hours
                ],
                'cache.weather' => [
                    'adapter' => 'cache.adapter.filesystem',
                    'default_lifetime' => 10800, // 3 hours
                ],
            ],
        ],
    ]);
};
