<?php

declare(strict_types=1);

use App\ComputationTracker\ComputationTracker;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Mercure\NullTripUpdatePublisher;
use App\Mercure\TripUpdatePublisher;
use App\Mercure\TripUpdatePublisherInterface;
use App\Push\FcmClient;
use App\Push\PushSenderInterface;
use App\Security\OAuth\McpCallBudget;
use App\Security\OAuth\McpScopeGuard;
use App\Serializer\Mcp\McpTextFloor;
use App\State\Mcp\McpConfirmationProcessor;
use App\State\Mcp\McpDeserializeProvider;
use App\State\PreconditionProcessor;
use App\State\TripLockProcessor;
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

    // The guards that stand between a caller and a write, declared against BOTH write chains.
    //
    // Measured, not deduced: api-platform/mcp builds a WriteProcessor of its own
    // (Resources/config/mcp/state.php) rather than reusing `api_platform.state_processor.write`,
    // so a decorator declared with #[AsDecorator('api_platform.state_processor.write')] guards
    // HTTP alone. The symptom was silent and expensive — `unshare_trip` revoked a share link on
    // the call that was only supposed to describe what revoking would do, because the
    // confirmation decorator was not in that chain at all. The lock and the precondition were
    // equally absent, which means a started trip and a stale version were being accepted too.
    //
    // The priorities are the same on both, and they are the counter-intuitive way round:
    // DecoratorServicePass hands the alias to the one it processes LAST, so the LOWEST priority
    // ends up outermost and runs FIRST. Hence lock (-10) before precondition (0) before
    // confirmation (10) — a token is only ever minted for a call that would have gone through.
    $writeGuards = [
        TripLockProcessor::class => -10,
        PreconditionProcessor::class => 0,
        McpConfirmationProcessor::class => 10,
    ];

    foreach ($writeGuards as $guard => $priority) {
        // The class id keeps the HTTP chain, replacing the definition `load()` discovered.
        $services->set($guard)
            ->decorate('api_platform.state_processor.write', null, $priority)
            ->args([service($guard.'.inner')])
            ->autowire()
            ->autoconfigure(false);

        // A second instance for MCP: one definition cannot decorate two services.
        $id = 'app.mcp_write_guard.'.$guard;
        $services->set($id, $guard)
            ->decorate('api_platform.mcp.state_processor.write', null, $priority)
            ->args([service($id.'.inner')])
            ->autowire()
            ->autoconfigure(false);
    }

    // What DeserializeProvider is to an HTTP body, this is to `tools/call` arguments — and the
    // priority is the design, not a detail.
    //
    // 250 lands it between ValidateProvider (200) and DeserializeProvider (300) in the main
    // provider chain, where the lowest priority is the outermost and therefore runs first:
    // content negotiation (100), parameter (180), parameter validator (191), validate (200),
    // THIS, deserialize (300), read (500). Both neighbours are load-bearing. Inside validation,
    // because the constraints have to judge the merged record — checked against the stored one
    // they pass while the new values go in unexamined. Outside ReadProvider, because it
    // publishes `previous_data` as a clone of whatever it just read: merge any deeper and the
    // record "before" the edit becomes a copy of the record after it, which silently defeats
    // both guards that read it (TripLockProcessor would judge a trip by the values the caller
    // just sent, TripUpdateProcessor would find nothing changed and recompute nothing).
    //
    // The main chain and not `api_platform.mcp.state_provider`: that id is an alias to this very
    // chain, so decorating it wraps the whole thing from the outside — after validation, which
    // is the one place this must not be. It is a no-op on HTTP, where the transport has a body
    // and DeserializeProvider already does this job. Declared here rather than by an attribute
    // because `load()` discovers the class and cannot autowire $decorated on its own.
    $services->set(McpDeserializeProvider::class)
        ->decorate('api_platform.state_provider.main', null, 250)
        ->args([service(McpDeserializeProvider::class.'.inner')])
        ->autowire()
        ->autoconfigure(false);

    // The scope is decided on each message the SDK has parsed, not on the `Mcp-Name` header: the
    // SDK unwraps an encoded header before checking it, and serves a handshake era that checks
    // none and accepts batches (see McpScopeGuard). One handler invocation per message is the
    // only place all three are the same thing.
    //
    // Decoration moves the `mcp.request_handler` tag onto the decorator — it is a role tag, and
    // DecoratorServicePass hands those over — so the SDK collects the guard, not the handler it
    // wraps. `autoconfigure(false)` because the bundle autoconfigures every
    // RequestHandlerInterface with that same tag, and `load()` has already discovered the class.
    $services->set(McpScopeGuard::class)
        ->decorate('api_platform.mcp.handler')
        ->args([service(McpScopeGuard::class.'.inner')])
        ->autowire()
        ->autoconfigure(false);

    // Counted per parsed message, like the scope. Priority 10 places it INSIDE the scope guard
    // (the lowest priority is the outermost): a call refused for its scope spends no budget.
    $services->set(McpCallBudget::class)
        ->decorate('api_platform.mcp.handler', null, 10)
        ->args([service(McpCallBudget::class.'.inner')])
        ->autowire()
        ->autoconfigure(false);

    // The serializer MCP tool answers go through, handed to StructuredContentProcessor alone by
    // App\DependencyInjection\McpTextFloorPass. `autoconfigure(false)` is load-bearing: the
    // class implements NormalizerInterface and EncoderInterface, so autoconfiguration would tag
    // it into the application's serializer chain — the one REST uses, and the one it wraps.
    $services->set(McpTextFloor::class)
        ->args([service('api_platform.serializer')])
        ->autowire(false)
        ->autoconfigure(false);

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
