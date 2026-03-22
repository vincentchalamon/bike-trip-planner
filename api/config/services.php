<?php

declare(strict_types=1);

use App\Mercure\NullTripUpdatePublisher;
use App\Mercure\TripUpdatePublisher;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\RedisTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('App\\', __DIR__.'/../src/');

    if ('test' === $containerConfigurator->env()) {
        $services->alias(TripUpdatePublisherInterface::class, NullTripUpdatePublisher::class);
        // Use Redis-backed repository in tests (no database available in PHPUnit).
        // TODO: add Foundry-based KernelTestCase integration tests with a real test database
        // to cover JSONB round-trips, UUID handling, and migration correctness (#56).
        $services->alias(TripRequestRepositoryInterface::class, RedisTripRequestRepository::class);
    } else {
        $services->alias(TripUpdatePublisherInterface::class, TripUpdatePublisher::class);
    }
};
