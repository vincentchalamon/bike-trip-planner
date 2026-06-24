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
                    // On-demand route fetch (Tier 3): retry transient failures
                    // (429/5xx + transport errors) with back-off; permanent client
                    // errors (404/403) are not retried, so they fail fast.
                    'retry_failed' => [
                        'max_retries' => 2,
                    ],
                    'headers' => [
                        'Accept' => 'text/html',
                        'User-Agent' => 'BikeTripPlanner/1.0',
                    ],
                ],
                'strava.client' => [
                    'base_uri' => 'https://www.strava.com',
                    'max_redirects' => 2,
                    'timeout' => 10,
                    'retry_failed' => [
                        'max_retries' => 2,
                    ],
                    'headers' => [
                        'Accept' => 'application/gpx+xml',
                        'User-Agent' => 'BikeTripPlanner/1.0',
                    ],
                ],
                'ridewithgps.client' => [
                    'base_uri' => 'https://ridewithgps.com',
                    'max_redirects' => 2,
                    'timeout' => 10,
                    'retry_failed' => [
                        'max_retries' => 2,
                    ],
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'BikeTripPlanner/1.0',
                    ],
                ],
                'open_meteo.client' => [
                    'base_uri' => 'https://api.open-meteo.com',
                    'timeout' => 10,
                    'max_redirects' => 2,
                    // Live, on-demand source (Tier 3): retry transient failures
                    // (429/5xx + transport errors) with exponential back-off rather
                    // than dropping the forecast on the first hiccup.
                    'retry_failed' => [
                        'max_retries' => 3,
                    ],
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
                'mercure.health.client' => [
                    'base_uri' => '%env(MERCURE_URL)%',
                    'max_redirects' => 0,
                    'timeout' => 2,
                ],
                // Per-user BYO-token AI providers (ADR-042). The symfony/ai-platform
                // bridge sends the user's key per request; these scoped clients add a
                // descriptive User-Agent + timeout and lock each one to its provider
                // host (SSRF). retry_failed retries transient provider failures
                // (429 and 503 "overloaded"/"high demand", POST included, with
                // exponential back-off); permanent 401/403 token errors are not
                // retried and degrade gracefully via the error taxonomy (ADR-042).
                'anthropic.client' => [
                    'scope' => '^https://api\\.anthropic\\.com',
                    'max_redirects' => 2,
                    'timeout' => 30,
                    'retry_failed' => [
                        'max_retries' => 3,
                    ],
                    'headers' => ['User-Agent' => 'BikeTripPlanner/1.0 (https://github.com/vincentchalamon/bike-trip-planner)'],
                ],
                'openai.client' => [
                    'scope' => '^https://api\\.openai\\.com',
                    'max_redirects' => 2,
                    'timeout' => 30,
                    'retry_failed' => [
                        'max_retries' => 3,
                    ],
                    'headers' => ['User-Agent' => 'BikeTripPlanner/1.0 (https://github.com/vincentchalamon/bike-trip-planner)'],
                ],
                'gemini.client' => [
                    'scope' => '^https://generativelanguage\\.googleapis\\.com',
                    'max_redirects' => 2,
                    'timeout' => 30,
                    'retry_failed' => [
                        'max_retries' => 3,
                    ],
                    'headers' => ['User-Agent' => 'BikeTripPlanner/1.0 (https://github.com/vincentchalamon/bike-trip-planner)'],
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
