<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * This import has to be written by hand.
 *
 * symfony/mcp-bundle ships a Routing\RouteLoader answering `supports($resource, 'mcp')`, but
 * its Flex recipe does not create the import: after `composer require symfony/mcp-bundle` the
 * only file added under config/ was http_discovery.yaml. Without this file the HTTP transport
 * is configured and no /mcp route exists — `debug:router` shows nothing and the server is
 * silently unreachable.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('.', 'mcp');
};
