<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The MCP server (ADR-064): a third client of the API, not an AI feature.
 *
 * Written as PHP because the repository has no YAML under config/; the Flex recipe for
 * symfony/mcp-bundle writes none of this anyway, see config/routes/mcp.php.
 *
 * `api_platform.mcp.format` is deliberately absent, and the reason has been re-measured since
 * ADR-064 was written. The upstream fix (api-platform/core #8542) IS in the v5.0.0 the lock
 * installs, and the parameter is no longer inert: FormatsResourceMetadataCollectionFactory
 * applies it to every MCP operation's input and output formats. It still does not change what
 * a tool answers with, because StructuredContentProcessor.php:66 normalizes with
 * `$request->getRequestFormat('') ?: 'jsonld'` — the format of the POST /mcp request, which is
 * never the operation's. Setting it would also mean registering a second format in
 * api_platform.formats, i.e. offering it on every REST endpoint too.
 *
 * So tool output carries the JSON-LD envelope, and the consequence is sharper than an extra
 * `@context`: every array property is rendered as a Hydra Collection carrying its VALUES only,
 * so an associative array arrives with its keys stripped. Measured, not deduced —
 * `{"route": "done"}` leaves as `{"member": ["done"]}`. Nothing in a tool's answer may be
 * keyed by data; see App\ApiResource\Mcp\CategoryStatus.
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
