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
            'trip_create' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '60 seconds',
                'cache_pool' => 'cache.rate_limiter',
            ],
            'accommodation_scrape' => [
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
