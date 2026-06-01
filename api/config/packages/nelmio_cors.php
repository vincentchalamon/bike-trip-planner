<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('nelmio_cors', [
        'defaults' => [
            'origin_regex' => true,
            'allow_origin' => [
                '%env(CORS_ALLOW_ORIGIN)%',
            ],
            'allow_methods' => [
                'GET',
                'OPTIONS',
                'POST',
                'PUT',
                'PATCH',
                'DELETE',
            ],
            'allow_headers' => [
                'Content-Type',
                'Authorization',
                // Correlation ID the PWA resends on every request (#485); required
                // so cross-origin clients (e.g. the Capacitor app) may send it.
                'X-Request-Id',
            ],
            'expose_headers' => [
                'Link',
                'X-Mercure-Token',
                'X-Request-Id',
            ],
            'max_age' => 3600,
        ],
        'paths' => [
            '^/' => null,
        ],
    ]);
};
