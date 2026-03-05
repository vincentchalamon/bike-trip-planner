<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
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
                        'Accept' => 'text/html',
                        'User-Agent' => 'BikeTripPlanner/1.0',
                    ],
                ],
                'overpass.local.client' => [
                    'base_uri' => 'http://overpass:80',
                    'timeout' => 5,
                ],
                'overpass.public.client' => [
                    'base_uri' => 'https://overpass-api.de',
                    'timeout' => 15,
                ],
                'open_meteo.client' => [
                    'base_uri' => 'https://api.open-meteo.com',
                    'timeout' => 10,
                ],
                'google_mymaps.client' => [
                    'base_uri' => 'https://www.google.com',
                    'max_redirects' => 5,
                    'timeout' => 15,
                ],
                'routing.client' => [
                    'base_uri' => 'http://valhalla:8002',
                    'timeout' => 5,
                ],
                'nominatim.client' => [
                    'base_uri' => 'https://nominatim.openstreetmap.org',
                    'timeout' => 10,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'BikeTripPlanner/1.0',
                    ],
                ],
                'accommodation_scraper.client' => [
                    'scope' => 'https?://.*',
                    'max_redirects' => 3,
                    'timeout' => 15,
                    'headers' => [
                        'Accept' => 'text/html',
                        'User-Agent' => 'Mozilla/5.0 (compatible; BikeTripPlanner/1.0)',
                    ],
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
