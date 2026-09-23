<?php

declare(strict_types=1);

use Http\Discovery\Psr17Factory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// PSR-17 factories, pulled in by php-http/discovery as a dependency of the MCP SDK.
// The Flex recipe writes this as YAML; the repository is PHP-only, so it is transcribed here
// rather than left as the only YAML in api/config.
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set('http_discovery.psr17_factory', Psr17Factory::class);

    foreach ([
        RequestFactoryInterface::class,
        ResponseFactoryInterface::class,
        ServerRequestFactoryInterface::class,
        StreamFactoryInterface::class,
        UploadedFileFactoryInterface::class,
        UriFactoryInterface::class,
    ] as $factoryInterface) {
        $services->alias($factoryInterface, 'http_discovery.psr17_factory');
    }
};
