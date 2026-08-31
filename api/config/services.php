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
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->parameters()
        // Refresh-token encryption key (SEC-003). PRODUCTION MUST set
        // REFRESH_TOKEN_ENC_KEY; the dev/CI default below only keeps the container
        // bootable (throwaway dev tokens, never used to protect real credentials).
        ->set('app.refresh_token_enc_key', '%env(default:default_refresh_token_enc_key:REFRESH_TOKEN_ENC_KEY)%')
        ->set('default_refresh_token_enc_key', 'dev-only-refresh-token-encryption-key-change-in-prod')
        // PG split (ADR-060): the reference connection URL defaults to the app
        // database URL, so dev/CI keep running on a single Postgres with no extra
        // configuration. Production points REFERENCE_DATABASE_URL at the shared
        // read-only PG-référence (wired through compose.yaml on php/worker).
        ->set('env(REFERENCE_DATABASE_URL)', '%env(DATABASE_URL)%');

    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure()
        // Bind the reference (read-only PG-référence) connection by parameter name
        // (ADR-060). Every raw-SQL reference repository / command takes a
        // `Connection $referenceConnection`; a plain `Connection` still autowires to
        // the default PG-app connection.
        ->bind('Doctrine\DBAL\Connection $referenceConnection', service('doctrine.dbal.reference_connection'));

    $services->load('App\\', __DIR__.'/../src/');

    $services->alias(PushSenderInterface::class, FcmClient::class);

    // SSRF defense-in-depth: wrap the third-party route fetchers so a redirect
    // (they allow max_redirects: 2) toward a private/loopback/link-local IP is
    // refused after DNS resolution — base_uri only locks the initial host, not a
    // 3xx Location. Applied to the clients that legitimately follow redirects.
    foreach (['komoot.client', 'strava.client', 'ridewithgps.client'] as $scopedClientId) {
        $services->set($scopedClientId.'.no_private_network', NoPrivateNetworkHttpClient::class)
            ->decorate($scopedClientId)
            ->args([service('.inner')])
            ->autowire(false)
            ->autoconfigure(false);
    }

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
