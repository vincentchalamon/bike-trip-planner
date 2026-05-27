<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;
use App\Logger\CorrelationIdProcessor;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;

/**
 * Handle-side middleware: pushes the originating HTTP correlation ID onto the
 * {@see CorrelationIdProcessor} for the duration of a worker handler so log
 * lines emitted by the handler carry the same `request_id` as the HTTP
 * request that dispatched the message.
 *
 * The override is always cleared in a `finally` block to avoid leaking
 * between consecutive messages handled by the same long-running worker.
 *
 * Synchronous dispatches (no `ConsumedByWorkerStamp`) are skipped — in that
 * case the {@see CorrelationIdProcessor} still resolves the request ID from
 * the active `RequestStack`.
 *
 * See issue #485.
 */
final readonly class HandleCorrelationIdMiddleware implements MiddlewareInterface
{
    public function __construct(private CorrelationIdProcessor $processor)
    {
    }

    #[\Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->last(ConsumedByWorkerStamp::class) instanceof StampInterface) {
            // Not running inside a worker (sync dispatch from a request) —
            // the processor already enriches logs from the RequestStack.
            return $stack->next()->handle($envelope, $stack);
        }

        $stamp = $envelope->last(CorrelationIdStamp::class);
        if (!$stamp instanceof CorrelationIdStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->processor->setOverrideRequestId($stamp->correlationId);

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->processor->setOverrideRequestId(null);
        }
    }
}
