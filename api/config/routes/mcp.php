<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * SPIKE FINDING — this file has to be written by hand.
 *
 * symfony/mcp-bundle ships a Routing\RouteLoader answering `supports($r, 'mcp')`,
 * but its Flex recipe does NOT create the routing import: after
 * `composer require symfony/mcp-bundle` the only file added under config/ was
 * http_discovery.yaml. Without this import the HTTP transport is configured but
 * NO /mcp route exists — `debug:router` shows nothing and the server is silently
 * unreachable.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('.', 'mcp');
};
