<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The MCP server (ADR-064): a third client of the API, not an AI feature.
 *
 * Written as PHP because the repository has no YAML under config/; the Flex recipe for
 * symfony/mcp-bundle writes none of this anyway, see config/routes/mcp.php.
 *
 * `api_platform.mcp.format` is deliberately absent. It is inert in api-platform/mcp v5.0.0
 * — StructuredContentProcessor.php:66 reads the request format and falls back to 'jsonld'
 * without ever consulting the operation's output formats. The upstream fix (api-platform/core
 * #8542) is merged but not released, so tool output carries the JSON-LD envelope and nothing
 * here pretends otherwise.
 */
return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('mcp', [
        'servers' => [
            'btp' => [
                'name' => 'bike-trip-planner',
                'version' => '1.0.0',
                'description' => 'Read and plan bikepacking trips.',
                'transports' => [
                    'http' => true,
                    'stdio' => false,
                ],
                'http' => [
                    'path' => '/mcp',
                    // DNS-rebinding protection. The SDK defaults to localhost only, which
                    // refuses every other Host header; the spike switched it off wholesale.
                    // Named instead, from the one URI the deployment already declares as its
                    // own — the same value the router builds absolute URLs from.
                    'allowed_hosts' => ['%env(key:host:url:DEFAULT_URI)%'],
                ],
                'session' => [
                    // FrankenPHP runs several workers: a file or in-memory store would not be
                    // shared between them.
                    'store' => 'cache',
                ],
                'registry' => [
                    'tools' => ['*'],
                ],
            ],
        ],
    ]);
};
