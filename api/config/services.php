<?php

declare(strict_types=1);

use App\ComputationTracker\ComputationTracker;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Mercure\NullTripUpdatePublisher;
use App\Mercure\TripUpdatePublisher;
use App\Mercure\TripUpdatePublisherInterface;
use App\Push\FcmClient;
use App\Push\PushSenderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    // The only HTTP client in this application whose HOST is chosen by a third party: an
    // OAuth client names itself by the HTTPS URL its metadata document is served from
    // (ADR-079). Every other outbound call goes through a scoped client locked to a base_uri,
    // with only a numeric id interpolated into a fixed path — that pattern cannot be reused
    // here, because the host IS the input.
    //
    // So it is unscoped, and everything else is closed instead: no redirect (a 3xx Location
    // is not constrained by anything), a short inactivity timeout AND a hard total duration
    // (a slow server must not be able to hold a worker), and one JSON document expected.
    // The size ceiling is enforced on the stream by the resolver, not after reading.
    $services->set('oauth_client_metadata.client', HttpClientInterface::class)
        ->factory([service('http_client'), 'withOptions'])
        ->args([[
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]]);

    // SSRF defense-in-depth: wrap the third-party route fetchers so a redirect
    // (they allow max_redirects: 2) toward a private/loopback/link-local IP is
    // refused after DNS resolution — base_uri only locks the initial host, not a
    // 3xx Location. Applied to the clients that legitimately follow redirects.
    //
    // `oauth_client_metadata.client` is in this list for a stronger reason than the other
    // three: they are already host-locked and this is their second line of defence, while
    // for the metadata client it is the ONLY thing standing between an attacker-supplied
    // hostname and the internal network. The check runs on the IP actually connected to,
    // so a name that resolves to a public address on the first lookup and a private one on
    // the second does not get through.
    foreach (['komoot.client', 'strava.client', 'ridewithgps.client', 'oauth_client_metadata.client'] as $scopedClientId) {
        $services->set($scopedClientId.'.no_private_network', NoPrivateNetworkHttpClient::class)
            ->decorate($scopedClientId)
            ->args([service('.inner')])
            ->autowire(false)
            ->autoconfigure(false);
    }

    // Two implementations exist since the persisting decorator (ADR-071), so the interface
    // no longer resolves on its own. It points at the decorated service id, which is the
    // decorator itself.
    $services->alias(ComputationTrackerInterface::class, ComputationTracker::class);

    if ('test' === $containerConfigurator->env()) {
        $services->alias(TripUpdatePublisherInterface::class, NullTripUpdatePublisher::class);
        // The trip repository is deliberately NOT aliased here any more. It used to point at a
        // transient implementation, which meant the suite exercised a repository production
        // never runs — and that is how `PATCH /trips/{id}` shipped dispatching nothing at all
        // for anyone. A test that green-lights code no user reaches is worse than no test.
    } else {
        $services->alias(TripUpdatePublisherInterface::class, TripUpdatePublisher::class);
    }
};
