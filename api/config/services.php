<?php

declare(strict_types=1);

use App\Mercure\NullTripUpdatePublisher;
use App\Mercure\TripUpdatePublisher;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\RedisTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->parameters()
        ->set('app.commit_sha', '%env(default:default_commit_sha:APP_COMMIT)%')
        ->set('default_commit_sha', 'unknown');

    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('App\\', __DIR__.'/../src/');

    // Dedicated lazy Redis client used by the readiness probe. Built via
    // RedisAdapter::createConnection so authentication, scheme parsing and
    // (later) Sentinel/Cluster setups are handled by Symfony Cache rather
    // than re-implemented in the controller. The connection is lazy so the
    // service can be constructed even when Redis is down — checkRedis() then
    // surfaces the failure via ->ping() instead of breaking DI.
    $services->set('app.redis.health', Redis::class)
        ->factory([RedisAdapter::class, 'createConnection'])
        ->args([
            '%env(REDIS_URL)%',
            ['lazy' => true, 'timeout' => 1.0, 'read_timeout' => 1.0],
        ]);

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
