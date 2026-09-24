<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\OAuth\McpAuthenticationEntryPoint;
use App\Security\OAuth\McpResource;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes every 401 from `/mcp` say the same thing (ADR-079).
 *
 * The entry point answers an anonymous call, but a call with a token the resource server
 * rejects does not go through it: `OAuth2Authenticator::onAuthenticationFailure()` builds
 * its own response and puts league's exception MESSAGE in the body. That message is written
 * for an operator, not for a caller — and this is the endpoint where an unauthenticated
 * stranger is doing the asking.
 *
 * So both paths end up with the same body and the same `WWW-Authenticate`, which is also
 * what a client needs in order to find the authorization server after a token expires rather
 * than only on its very first call.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class McpUnauthorizedResponseListener
{
    public function __construct(
        private McpResource $resource,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/mcp')) {
            return;
        }

        if (Response::HTTP_UNAUTHORIZED !== $event->getResponse()->getStatusCode()) {
            return;
        }

        $event->setResponse(McpAuthenticationEntryPoint::unauthorized($this->resource));
    }
}
