<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * SPIKE — throwaway. Minimal MCP server exposing read-only tools over HTTP.
 *
 * Written as PHP, not YAML: the project runs config-transformer, and the Flex
 * recipe for symfony/mcp-bundle would otherwise re-introduce a .yaml here.
 */
return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('mcp', [
        'servers' => [
            'btp' => [
                'name' => 'bike-trip-planner',
                'version' => '0.1.0',
                'description' => 'Read-only access to your bikepacking trips.',
                'transports' => [
                    'http' => true,
                    'stdio' => false,
                ],
                'http' => [
                    'path' => '/mcp',
                    // Spike only: the SDK default restricts DNS-rebinding protection to
                    // localhost, which refuses any other Host header.
                    'allowed_hosts' => false,
                ],
                'session' => [
                    // FrankenPHP runs several workers: a file/memory store would not be
                    // shared between them.
                    'store' => 'cache',
                ],
                'registry' => [
                    'tools' => ['*'],
                ],
            ],
        ],
    ]);

    // SPIKE FINDING: `'mcp' => ['format' => 'json']` is REFUSED —
    //   "The MCP format "json" is not configured in api_platform.formats."
    // Only `jsonld` is declared in api_platform.php, and the MCP format must be one
    // of the globally registered formats. Serving MCP as plain JSON therefore means
    // adding `'json' => ['application/json']` to api_platform.formats, which also
    // hands every REST operation a format it does not advertise today — an OpenAPI
    // and core/schema.d.ts contract change. Left on the jsonld default here; the
    // trade-off is recorded in the verdict.
};
