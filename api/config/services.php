<?php

declare(strict_types=1);

use App\Mercure\NullTripUpdatePublisher;
use App\Mercure\TripUpdatePublisher;
use App\Mercure\TripUpdatePublisherInterface;
use App\Push\FcmClient;
use App\Push\PushSenderInterface;
use App\Repository\RedisTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->parameters()
        // Refresh-token encryption key (SEC-003). PRODUCTION MUST set
        // REFRESH_TOKEN_ENC_KEY; the dev/CI default below only keeps the container
        // bootable (throwaway dev tokens, never used to protect real credentials).
        ->set('app.refresh_token_enc_key', '%env(default:default_refresh_token_enc_key:REFRESH_TOKEN_ENC_KEY)%')
        ->set('default_refresh_token_enc_key', 'dev-only-refresh-token-encryption-key-change-in-prod');

    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('App\\', __DIR__.'/../src/');

    $services->alias(PushSenderInterface::class, FcmClient::class);

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
