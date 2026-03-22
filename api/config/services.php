<?php

declare(strict_types=1);

use App\Mercure\NullTripUpdatePublisher;
use App\Mercure\TripUpdatePublisher;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\RedisTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
use App\Test\MockKomootClientFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('App\\', __DIR__.'/../src/');

    if ('test' === $containerConfigurator->env()) {
        $services->alias(TripUpdatePublisherInterface::class, NullTripUpdatePublisher::class);
        // Use Redis-backed repository in tests (no database available)
        $services->alias(TripRequestRepositoryInterface::class, RedisTripRequestRepository::class);
    } else {
        $services->alias(TripUpdatePublisherInterface::class, TripUpdatePublisher::class);
    }

    // Decorate komoot.client to serve local HTML fixtures when MOCK_EXTERNAL_HTTP=true.
    // Runtime check (not compile-time) so it works with precompiled prod Docker images in CI.
    $services->set(MockKomootClientFactory::class)
        ->decorate('komoot.client')
        ->args([service('.inner')]);
};
