<?php

declare(strict_types=1);

namespace App\Logger;

use App\Entity\User;
use App\EventListener\RequestIdListener;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Bundle\SecurityBundle\Security;

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
 *   dispatched them. Precedence: {@see CorrelationContext}.
 * - `user_id`: the authenticated user's UUID (when a {@see Security} context
 *   is available).
 * - `trip_id`: the trip UUID read from the request attributes (`{tripId}` /
 *   `{id}` path parameters) when present, see {@see CorrelationContext::tripId()}.
 *
 * The processor is intentionally side-effect-free and never throws: a missing
 * RequestStack/Security in CLI contexts simply yields a no-op enrichment.
 */
final readonly class CorrelationIdProcessor implements ProcessorInterface
{
    public function __construct(
        private CorrelationContext $correlation,
        private Security $security,
    ) {
    }

    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        $requestId = $this->correlation->requestId();
        if (null !== $requestId) {
            $extra['request_id'] = $requestId;
        }

        $userId = $this->resolveUserId();
        if (null !== $userId) {
            $extra['user_id'] = $userId;
        }

        $tripId = $this->correlation->tripId();
        if (null !== $tripId) {
            $extra['trip_id'] = $tripId;
        }

        return $record->with(extra: $extra);
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
}
