<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Messenger stamp carrying the HTTP correlation ID (`X-Request-Id`) across the
 * async bus boundary.
 *
 * Stamped onto the envelope by {@see SendCorrelationIdMiddleware} when a
 * message is dispatched from a request context. Read back by
 * {@see HandleCorrelationIdMiddleware} on the worker side so the
 * {@see \App\Logger\CorrelationIdProcessor} can enrich worker logs with the
 * originating `request_id`. See issue #485.
 */
final readonly class CorrelationIdStamp implements StampInterface
{
    public function __construct(public string $correlationId)
    {
    }
}
