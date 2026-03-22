<?php

declare(strict_types=1);

use App\Mercure\NullTripUpdatePublisher;
use App\Mercure\TripUpdatePublisher;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\RedisTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
use App\Test\MockKomootClientFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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

        // Replace komoot.client with a MockHttpClient that serves local HTML fixtures,
        // making the integration smoke test deterministic and independent of external network.
        $services->set('komoot.client', Symfony\Contracts\HttpClient\HttpClientInterface::class)
            ->factory([MockKomootClientFactory::class, 'create']);
    } else {
        $services->alias(TripUpdatePublisherInterface::class, TripUpdatePublisher::class);
    }
};
