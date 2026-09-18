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

    $containerConfigurator->extension('api_platform', ['mcp' => ['format' => 'json']]);

    // SPIKE FINDING — `api_platform.mcp.format` is INERT in v4.3.19.
    //
    // Setting it to 'json' is first refused unless 'json' is also registered in
    // api_platform.formats. Register it, set the global, clear the cache, verify the
    // parameter really holds "json" in the container — and the tool output is STILL
    // JSON-LD, in `content[0].text` AND in `structuredContent`:
    //   {"@context":"/contexts/TripDetail","@type":"TripDetail","id":...}
    // Declaring outputFormats on the McpTool itself is accepted too, and equally
    // ineffective. There is currently no way to avoid the @context/@type envelope.
    // Left unset: neither form buys anything.
};
