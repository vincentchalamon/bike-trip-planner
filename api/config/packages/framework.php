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
            'scoped_clients' => [
                'komoot.client' => [
                    'base_uri' => 'https://www.komoot.com',
                    'max_redirects' => 2,
                    'timeout' => 10,
                    'headers' => [
                        'Accept' => 'application/gpx+xml, text/html',
                    ],
                ],
                'overpass.client' => [
                    'base_uri' => 'https://overpass-api.de',
                    'timeout' => 30,
                ],
                'weather.client' => [
                    'base_uri' => 'https://openweathermap.org',
                    'timeout' => 10,
                ],
                'google_mymaps.client' => [
                    'base_uri' => 'https://www.google.com',
                    'max_redirects' => 5,
                    'timeout' => 15,
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
