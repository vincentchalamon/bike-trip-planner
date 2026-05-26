<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Reads (or mints) the `X-Request-Id` correlation header on each HTTP request
 * and ensures it is exposed on the outgoing response.
 *
 * Caddy already injects an `X-Request-Id` upstream in production (see
 * `.docker/caddy/Caddyfile`), but the listener also acts as a safety net for
 * traffic that bypasses Caddy (PHPUnit `ApiTestCase`, internal sub-requests,
 * worker-triggered HTTP calls, etc.) so every request carries a stable ID for
 * Monolog/Mercure/Messenger tracing.
 *
 * The chosen identifier is stored on the Request attributes under
 * `_correlation_id` so the rest of the application can read it back through
 * `RequestStack` (see {@see \App\Logger\CorrelationIdProcessor},
 * {@see \App\Messenger\SendCorrelationIdMiddleware},
 * {@see \App\Mercure\TripUpdatePublisher}).
 */
final class RequestIdListener
{
    public const string HEADER = 'X-Request-Id';

    public const string ATTRIBUTE = '_correlation_id';

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        $correlationId = $request->headers->get(self::HEADER);
        if (null === $correlationId || '' === trim($correlationId) || !$this->isSafe($correlationId)) {
            $correlationId = Uuid::v7()->toRfc4122();
            $request->headers->set(self::HEADER, $correlationId);
        }

        $request->attributes->set(self::ATTRIBUTE, $correlationId);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $request = $event->getRequest();

        $correlationId = $request->attributes->get(self::ATTRIBUTE);
        if (\is_string($correlationId) && '' !== $correlationId && !$response->headers->has(self::HEADER)) {
            $response->headers->set(self::HEADER, $correlationId);
        }

        // Expose the header to browser JavaScript through CORS so the PWA can
        // read it from cross-origin responses (Nelmio CORS handles the rest).
        $exposed = (string) $response->headers->get('Access-Control-Expose-Headers', '');
        if (!str_contains(strtolower($exposed), strtolower(self::HEADER))) {
            $response->headers->set(
                'Access-Control-Expose-Headers',
                '' === $exposed ? self::HEADER : $exposed.', '.self::HEADER,
                false,
            );
        }
    }

    /**
     * Bounds the accepted header to ASCII-safe characters to avoid log
     * injection / header smuggling when the value is forwarded to logs or
     * downstream services. Anything outside [-A-Za-z0-9_] (up to 128 chars)
     * is rejected and a fresh UUID v7 is minted instead.
     */
    private function isSafe(string $value): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{8,128}$/', $value);
    }

    /**
     * Helper for callers that only have a {@see Response} reference (e.g. test
     * doubles) and need to assert the listener wired the response header.
     */
    public static function fromResponse(Response $response): ?string
    {
        return $response->headers->get(self::HEADER);
    }
}
