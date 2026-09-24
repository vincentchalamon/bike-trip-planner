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
                // so cross-origin clients may send it.
                'X-Request-Id',
                // Structural-version precondition on every edit (ADR-067). Without it the
                // preflight refuses the header and the mobile app — which is cross-origin,
                // unlike the PWA behind the same Caddy — could not send a precondition at all.
                'If-Match',
            ],
            'expose_headers' => [
                // How an MCP client discovers the authorization server, and how it learns
                // which scope it is missing (ADR-079). Unexposed, a browser-based client
                // reads a 401 or a 403 with no way forward.
                'WWW-Authenticate',
                'Link',
                'X-Request-Id',
                // The trip version a client pins back with If-Match (ADR-067). A header the
                // browser does not expose is one the client silently never reads, which would
                // leave every edit pinning nothing.
                'ETag',
                // Where the accepted work can be read once it lands (ADR-074). The JSON-LD
                // body already carries the same URI as `@id`, so this is for a consumer that
                // does not parse JSON-LD — the agent this API is being restructured for.
                'Location',
            ],
            'max_age' => 3600,
        ],
        'paths' => [
            '^/' => null,
        ],
    ]);
};
