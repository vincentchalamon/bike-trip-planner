<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\HttpFoundation\Request;
use App\EventListener\RequestIdListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Dispatch-side middleware: stamps each outgoing envelope with the current
 * HTTP correlation ID so workers can rejoin the original trace.
 *
 * Reads the `X-Request-Id` value pinned by {@see RequestIdListener} on the
 * main request and attaches a {@see CorrelationIdStamp}. If the dispatch
 * happens outside an HTTP request (CLI command, worker chaining another
 * message), any pre-existing stamp is left untouched so chained messages
 * keep the original ID.
 *
 * See issue #485.
 */
final readonly class SendCorrelationIdMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    #[\Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(CorrelationIdStamp::class) instanceof StampInterface) {
            // The envelope already carries a correlation ID (chained dispatch
            // from a worker handler). Preserve it.
            return $stack->next()->handle($envelope, $stack);
        }

        $request = $this->requestStack->getMainRequest();
        if ($request instanceof Request) {
            $correlationId = $request->attributes->get(RequestIdListener::ATTRIBUTE);
            if (\is_string($correlationId) && '' !== $correlationId) {
                $envelope = $envelope->with(new CorrelationIdStamp($correlationId));
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
