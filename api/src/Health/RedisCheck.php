<?php

declare(strict_types=1);

namespace App\Health;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Redis answers a PING. Required: the Messenger transport, the computation tracker and the
 * locks all live there.
 */
#[AsTaggedItem(priority: 50)]
final readonly class RedisCheck implements HealthCheck
{
    use MeasuresCheck;

    public function __construct(
        private RedisHealthClientFactory $redisClientFactory,
    ) {
    }

    public function name(): string
    {
        return 'redis';
    }

    public function isRequired(): bool
    {
        return true;
    }

    public function start(): \Closure
    {
        return $this->check(...);
    }

    /**
     * @return array{status: string, latency_ms: int, error?: string}
     */
    private function check(): array
    {
        $start = hrtime(true);

        try {
            // A fresh connection per check (see RedisHealthClientFactory): never
            // reuse a connection a Redis restart may have broken in the worker.
            $pong = $this->redisClientFactory->create()->ping();
            $ok = true === $pong || '+PONG' === $pong || 'PONG' === $pong;

            return [
                'status' => $ok ? 'ok' : 'down',
                'latency_ms' => $this->elapsedMs($start),
            ];
        } catch (\Throwable $throwable) {
            return [
                'status' => 'down',
                'latency_ms' => $this->elapsedMs($start),
                'error' => $this->sanitizeError($throwable),
            ];
        }
    }
}
