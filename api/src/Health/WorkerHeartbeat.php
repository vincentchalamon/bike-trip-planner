<?php

declare(strict_types=1);

namespace App\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Records that a Messenger consumer is alive, so readiness can say so (ADR-075).
 *
 * The Redis consumer group cannot answer this: the transport DSN supplies only the
 * stream name, so every replica registers under Symfony's default consumer name
 * `consumer` and XINFO CONSUMERS reports one entry whatever the replica count. Each
 * worker therefore writes its own member into a sorted set scored by wall-clock, and
 * the probe counts the members scored inside the window.
 *
 * The window is deliberately wide. WorkerRunningEvent fires once per processed
 * message and once per idle loop, but *never during* a handler — and a scan over a
 * long trip takes minutes. A tight window would declare a busy worker dead.
 */
readonly class WorkerHeartbeat
{
    /**
     * How long a beat vouches for its worker. Wider than the slowest handler, and wide
     * enough to swallow the hourly gap when `--time-limit=3600` retires every replica
     * at once (`restart: unless-stopped` brings them back in seconds).
     */
    public const int ALIVE_WINDOW = 300;

    /** Minimum delay between two beats from the same worker. */
    public const int BEAT_INTERVAL = 10;

    public function __construct(
        private RedisHealthClientFactory $redisClientFactory,
        #[Autowire(param: 'kernel.environment')]
        private string $env,
    ) {
    }

    /**
     * Namespaced per environment on purpose: phpunit.dist.xml overrides the Messenger
     * DSNs but not REDIS_URL, and the cache pools fall back to the array adapter, so
     * this set is the only thing the test suite writes to the Redis a dev stack shares.
     * Without the suffix, running the tests would wipe the real workers' beats and
     * register `phpunit` as a phantom worker in the running application's health view.
     */
    public function key(): string
    {
        return \sprintf('health.workers.%s', $this->env);
    }

    public function beat(string $workerId): void
    {
        $redis = $this->redisClientFactory->create();
        $now = time();

        $redis->zAdd($this->key(), $now, $workerId);
        // Pruning belongs to the worker, not to the probe: a readiness call must stay a read.
        $redis->zRemRangeByScore($this->key(), '-inf', (string) ($now - self::ALIVE_WINDOW));
        // So the key disappears on its own once every worker is gone. Redis runs with
        // `volatile-lru`, which makes a TTL-bearing key evictable — this one is rewritten
        // every BEAT_INTERVAL seconds and holds a handful of members, so it is the last
        // candidate under pressure.
        $redis->expire($this->key(), self::ALIVE_WINDOW);
    }

    public function aliveCount(): int
    {
        return $this->redisClientFactory->create()->zCount(
            $this->key(),
            (string) (time() - self::ALIVE_WINDOW),
            '+inf',
        );
    }
}
