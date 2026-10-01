<?php

declare(strict_types=1);

namespace App\Health;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Reports the async tier: whether anything is consuming, and how much is waiting.
 *
 * Required, and it is the point of the required list: the whole product answers 202 and
 * delegates to a worker, so no live consumer means nothing the API accepts will ever complete
 * (ADR-075). A dead consumer used to leave the probe green while every trip stayed `pending`
 * until the tracker expired half an hour later. That is the verdict here, and the only one:
 * the two depths are reported without a threshold. A depth threshold would be red whenever the
 * system is merely busy, the same mistake ADR-041 R5 was withdrawn for (#877), and comparing
 * the live count to WORKER_REPLICAS would be red through every rolling restart. An absence is
 * unambiguous; a number is not.
 */
#[AsTaggedItem(priority: 30)]
final readonly class MessengerCheck implements HealthCheck
{
    use MeasuresCheck;

    /**
     * Redis stream and consumer-group names behind the Messenger transports.
     *
     * Hardcoded rather than derived from the transports: reading a depth through
     * `messenger.receiver_locator` would go through the transport's own Connection,
     * which memoises its \Redis handle. Under FrankenPHP worker mode that handle
     * outlives a Redis restart, ext-redis does not reconnect, and readiness would
     * answer 503 forever — the very failure RedisHealthClientFactory exists to avoid.
     * The DSNs in compose.yaml supply only the stream (`/messages`, `/failed`), so the
     * group stays Symfony's default.
     */
    private const string ASYNC_STREAM = 'messages';

    private const string FAILED_STREAM = 'failed';

    private const string CONSUMER_GROUP = 'symfony';

    public function __construct(
        private WorkerHeartbeat $workerHeartbeat,
        private RedisHealthClientFactory $redisClientFactory,
    ) {
    }

    public function name(): string
    {
        return 'messenger';
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
     * @return array{status: string, latency_ms: int, workers_alive: int, queue_depth: int|null, failed_depth: int|null, error?: string}
     */
    private function check(): array
    {
        $start = hrtime(true);

        try {
            $alive = $this->workerHeartbeat->aliveCount();
            $redis = $this->redisClientFactory->create();

            return [
                'status' => 0 === $alive ? 'down' : 'ok',
                'latency_ms' => $this->elapsedMs($start),
                'workers_alive' => $alive,
                'queue_depth' => $this->streamBacklog($redis, self::ASYNC_STREAM),
                // Nothing consumes `failed` (the worker only runs `messenger:consume async`),
                // so this is a dead-letter counter, not a backlog.
                'failed_depth' => $this->streamBacklog($redis, self::FAILED_STREAM),
            ];
        } catch (\Throwable $throwable) {
            return [
                'status' => 'down',
                'latency_ms' => $this->elapsedMs($start),
                'workers_alive' => 0,
                'queue_depth' => null,
                'failed_depth' => null,
                'error' => $this->sanitizeError($throwable),
            ];
        }
    }

    /**
     * Undelivered entries left for the consumer group, or null when the stream does not
     * exist yet (nothing has ever been enqueued) or the server predates the `lag` field.
     * In-flight, delivered-but-unacked entries are not counted.
     */
    private function streamBacklog(\Redis $redis, string $stream): ?int
    {
        try {
            /** @var list<array<string, mixed>>|false $groups */
            $groups = $redis->xInfo('GROUPS', $stream);
        } catch (\Throwable) {
            // XINFO raises on a stream that was never written to. An empty queue is not
            // a failure, and it must not drag the worker verdict down with it.
            return null;
        }

        if (!\is_array($groups)) {
            return null;
        }

        foreach ($groups as $group) {
            if (self::CONSUMER_GROUP !== ($group['name'] ?? null)) {
                continue;
            }

            $lag = $group['lag'] ?? null;

            return is_numeric($lag) ? (int) $lag : null;
        }

        return null;
    }
}
