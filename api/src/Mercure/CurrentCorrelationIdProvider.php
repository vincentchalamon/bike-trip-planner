<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\HttpFoundation\Request;
use App\EventListener\RequestIdListener;
use App\Logger\CorrelationIdProcessor;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the current correlation ID for Mercure publish payloads from
 * whichever context is active:
 *
 * - inside an HTTP request: the value pinned by {@see RequestIdListener} on
 *   the main request attributes;
 * - inside a Messenger worker handler: the override pushed onto
 *   {@see CorrelationIdProcessor} by
 *   {@see \App\Messenger\HandleCorrelationIdMiddleware}.
 *
 * Returns `null` when no correlation context is active (e.g. raw CLI command),
 * letting {@see TripUpdatePublisher} omit the field from the SSE payload.
 *
 * See issue #485.
 */
final readonly class CurrentCorrelationIdProvider
{
    public function __construct(
        private RequestStack $requestStack,
        private CorrelationIdProcessor $processor,
    ) {
    }

    public function current(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if ($request instanceof Request) {
            $attr = $request->attributes->get(RequestIdListener::ATTRIBUTE);
            if (\is_string($attr) && '' !== $attr) {
                return $attr;
            }

            $header = $request->headers->get(RequestIdListener::HEADER);
            if (\is_string($header) && '' !== $header) {
                return $header;
            }
        }

        return $this->processor->getOverrideRequestId();
    }
}
