<?php

declare(strict_types=1);

namespace App\Logger;

use Symfony\Component\HttpFoundation\Request;
use App\Entity\User;
use App\EventListener\RequestIdListener;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Enriches every Monolog record with correlation metadata so log lines can be
 * stitched back together across the Caddy → Symfony → Messenger → Mercure
 * pipeline. See issue #485.
 *
 * Three fields are added under `extra` when available:
 *
 * - `request_id`: the value of the `X-Request-Id` header forwarded by Caddy
 *   (or minted by {@see RequestIdListener}). On worker handlers, the value is
 *   restored from the {@see \App\Messenger\CorrelationIdStamp} carried by the
 *   message envelope so async logs share the same ID as the HTTP request that
 *   dispatched them.
 * - `user_id`: the authenticated user's UUID (when a {@see Security} context
 *   is available).
 * - `trip_id`: the trip UUID read from the request attributes (`{tripId}` /
 *   `{id}` path parameters) when present.
 *
 * The processor is intentionally side-effect-free and never throws: a missing
 * RequestStack/Security in CLI contexts simply yields a no-op enrichment.
 */
final class CorrelationIdProcessor implements ProcessorInterface
{
    /**
     * Path-attribute keys that explicitly name a trip identifier (used by
     * sub-resource routes like `/trips/{tripId}/stages/{index}`). The generic
     * `id` parameter is handled separately in {@see self::resolveTripId()} so
     * we never mislabel a user/stage UUID as `trip_id` on non-trip routes.
     */
    private const array TRIP_ATTRIBUTES = ['tripId', 'trip_id'];

    /**
     * Worker-side correlation override. Populated by
     * {@see \App\Messenger\HandleCorrelationIdMiddleware} for the duration of
     * a single message handling so log records emitted from the worker carry
     * the same `request_id` as the originating HTTP request.
     */
    private ?string $overrideRequestId = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
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

    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        $requestId = $this->resolveRequestId();
        if (null !== $requestId) {
            $extra['request_id'] = $requestId;
        }

        $userId = $this->resolveUserId();
        if (null !== $userId) {
            $extra['user_id'] = $userId;
        }

        $tripId = $this->resolveTripId();
        if (null !== $tripId) {
            $extra['trip_id'] = $tripId;
        }

        return $record->with(extra: $extra);
    }

    private function resolveRequestId(): ?string
    {
        if (null !== $this->overrideRequestId && '' !== $this->overrideRequestId) {
            return $this->overrideRequestId;
        }

        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return null;
        }

        $attr = $request->attributes->get(RequestIdListener::ATTRIBUTE);
        if (\is_string($attr) && '' !== $attr) {
            return $attr;
        }

        $header = $request->headers->get(RequestIdListener::HEADER);

        return \is_string($header) && '' !== $header ? $header : null;
    }

    private function resolveUserId(): ?string
    {
        try {
            $user = $this->security->getUser();
        } catch (\Throwable) {
            // Security context can be unavailable in CLI/Messenger workers —
            // never let logging fail because of authentication wiring.
            return null;
        }

        if (!$user instanceof User) {
            return null;
        }

        return $user->getId()->toRfc4122();
    }

    private function resolveTripId(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return null;
        }

        foreach (self::TRIP_ATTRIBUTES as $key) {
            $value = $request->attributes->get($key);
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        // The Trip resource uses the default API Platform `{id}` parameter
        // (`/trips/{id}`, `/trips/{id}/duplicate`, …). Only treat that
        // parameter as a trip id when the path is unambiguously trip-scoped
        // so we never mislabel a user/stage UUID emitted from other resources.
        if (str_starts_with($request->getPathInfo(), '/trips/')) {
            $value = $request->attributes->get('id');
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
