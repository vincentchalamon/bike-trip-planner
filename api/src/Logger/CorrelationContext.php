<?php

declare(strict_types=1);

namespace App\Logger;

use App\EventListener\RequestIdListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The correlation identifiers of whatever is running now, resolved in one place for the log
 * processor, the Sentry scope and the Mercure payloads (#485).
 *
 * Request id precedence: the worker override first, then the `_correlation_id` attribute
 * pinned by {@see RequestIdListener}, then the raw `X-Request-Id` header. The override is only
 * ever set by {@see \App\Messenger\HandleCorrelationIdMiddleware}, for the duration of one
 * message consumed by a worker: it is the narrower scope, and the only value that names the
 * HTTP request which dispatched the work. The header fallback covers a request the listener
 * did not see (a test building its own Request).
 */
final class CorrelationContext
{
    /**
     * Path-attribute keys that explicitly name a trip identifier (sub-resource routes like
     * `/trips/{tripId}/stages/{index}`). The generic `id` is only read on trip-scoped paths,
     * see {@see self::tripId()}.
     */
    private const array TRIP_ATTRIBUTES = ['tripId', 'trip_id'];

    private ?string $overrideRequestId = null;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function setOverrideRequestId(?string $requestId): void
    {
        $this->overrideRequestId = $requestId;
    }

    public function getOverrideRequestId(): ?string
    {
        return $this->overrideRequestId;
    }

    public function requestId(): ?string
    {
        if (null !== $this->overrideRequestId && '' !== $this->overrideRequestId) {
            return $this->overrideRequestId;
        }

        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return null;
        }

        return $this->string($request->attributes->get(RequestIdListener::ATTRIBUTE))
            ?? $this->string($request->headers->get(RequestIdListener::HEADER));
    }

    public function tripId(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return null;
        }

        foreach (self::TRIP_ATTRIBUTES as $key) {
            $value = $this->string($request->attributes->get($key));
            if (null !== $value) {
                return $value;
            }
        }

        // The Trip resource uses API Platform's default `{id}` (`/trips/{id}`,
        // `/trips/{id}/duplicate`, ...). Elsewhere `id` names a user or a stage, which must
        // never be labelled `trip_id`.
        if (str_starts_with($request->getPathInfo(), '/trips/')) {
            return $this->string($request->attributes->get('id'));
        }

        return null;
    }

    private function string(mixed $value): ?string
    {
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
