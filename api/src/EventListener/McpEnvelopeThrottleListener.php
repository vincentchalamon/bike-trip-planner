<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * A ceiling on `/mcp` per address, for what no other limiter can see.
 *
 * The per-call budgets ({@see \App\Security\OAuth\McpCallBudget}) are keyed on an agent's
 * identity, so they only exist once the firewall has one. The firewall runs at priority 8: a
 * request with no token, or a forged one, is turned away there and never reaches anything that
 * counts it. Priority 9 is therefore not a detail — it is the only place an anonymous flood is
 * bounded at all.
 *
 * Per request, deliberately coarse, and generous: several agents may share one address behind a
 * NAT, and the fine-grained budget is the per-call one. This one exists so that the endpoint
 * has a ceiling, and it answers the way HTTP clients understand — 429 with `Retry-After`.
 *
 * ⚠ A caller whose address cannot be resolved is counted with everyone else, not exempted —
 * the same rule as {@see OAuthEndpointThrottleListener}: not being able to tell callers apart is
 * a reason to put them in one bucket, not a reason to stop counting.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final readonly class McpEnvelopeThrottleListener
{
    public function __construct(
        #[Autowire(service: 'limiter.mcp_envelope')]
        private RateLimiterFactory $limiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/mcp')) {
            return;
        }

        $limit = $this->limiter->create($request->getClientIp() ?? 'unknown')->consume();

        if ($limit->isAccepted()) {
            return;
        }

        $response = new JsonResponse([
            'error' => 'rate_limited',
            'error_description' => 'Too many requests from this address.',
        ], Response::HTTP_TOO_MANY_REQUESTS);
        $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

        $event->setResponse($response);
    }
}
