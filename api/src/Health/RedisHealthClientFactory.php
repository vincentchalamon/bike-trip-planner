<?php

declare(strict_types=1);

namespace App\Health;

use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds a fresh \Redis connection on every call. The readiness probe must never
 * reuse a connection broken by a Redis restart: ext-redis does not auto-reconnect,
 * so a long-lived (shared) FrankenPHP worker holding a single connection would
 * report Redis "down" forever after a restart. RedisAdapter handles scheme parsing
 * and auth from REDIS_URL. A fresh sub-ms connection per check keeps readiness
 * accurate while remaining a seam the functional tests can replace via the
 * container (no $_ENV/$_SERVER mutation).
 */
readonly class RedisHealthClientFactory
{
    private const float TIMEOUT = 1.0;

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private string $redisUrl,
    ) {
    }

    public function create(): \Redis
    {
        $connection = RedisAdapter::createConnection(
            $this->redisUrl,
            ['timeout' => self::TIMEOUT, 'read_timeout' => self::TIMEOUT],
        );
        \assert($connection instanceof \Redis);

        return $connection;
    }
}
