<?php

declare(strict_types=1);

namespace App\Sentry;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Sentry `before_send` callback (P1.1).
 *
 * Drops well-known noise from the ingestion pipeline so the GlitchTip free
 * quota and triage queue stay focused on actual server-side bugs:
 *
 *   - Any {@see HttpExceptionInterface} below status 500 is a client error
 *     (404, 405, 400, 401, 403, 409, …) — crawler / bad-URL noise or already
 *     returned to the caller as a structured API Problem response.
 *   - {@see ValidationFailedException} is the expected outcome of an invalid
 *     payload and should not page anyone.
 *
 * Everything else — including 5xx HttpException and any unhandled throwable —
 * is forwarded untouched.
 */
final class ExceptionFilter
{
    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        $exception = $hint?->exception;
        if (!$exception instanceof \Throwable) {
            return $event;
        }

        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return null;
        }

        if ($exception instanceof ValidationFailedException) {
            return null;
        }

        return $event;
    }
}
