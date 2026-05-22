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
                        'Accept' => 'text/html',
                        'User-Agent' => 'BikeTripPlanner/1.0',
                    ],
                ],
                'strava.client' => [
                    'base_uri' => 'https://www.strava.com',
                    'max_redirects' => 2,
                    'timeout' => 10,
                    'headers' => [
                        'Accept' => 'application/gpx+xml',
                        'User-Agent' => 'BikeTripPlanner/1.0',
                    ],
                ],
                'ridewithgps.client' => [
                    'base_uri' => 'https://ridewithgps.com',
                    'max_redirects' => 2,
                    'timeout' => 10,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'BikeTripPlanner/1.0',
                    ],
                ],
                'overpass.client' => [
                    'base_uri' => 'https://overpass-api.de',
                    'timeout' => 15,
                ],
                'open_meteo.client' => [
                    'base_uri' => 'https://api.open-meteo.com',
                    'timeout' => 10,
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
                'datatourisme.client' => [
                    'scope' => '^https://api\\.datatourisme\\.fr',
                    'max_redirects' => 2,
                    'timeout' => 10,
                    'headers' => [
                        'X-API-Key' => '%env(default::DATATOURISME_API_KEY)%',
                        'Accept' => 'application/json',
                    ],
                ],
                'wikidata.client' => [
                    'scope' => '^https://query\\.wikidata\\.org',
                    'max_redirects' => 2,
                    'timeout' => 10,
                    'headers' => [
                        'User-Agent' => '%env(WIKIDATA_USER_AGENT)%',
                        'Accept' => 'application/sparql-results+json',
                    ],
                ],
                'markets.client' => [
                    'scope' => '^https://www\\.data\\.gouv\\.fr',
                    'max_redirects' => 2,
                    'timeout' => 60,
                ],
                'ollama.client' => [
                    'base_uri' => '%env(OLLAMA_BASE_URL)%',
                    'max_redirects' => 0,
                    'timeout' => 30,
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
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
