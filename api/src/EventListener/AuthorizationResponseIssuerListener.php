<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\OAuth\McpResource;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Names the issuer in the authorization response (RFC 9207, ADR-079).
 *
 * A client that talks to more than one authorization server cannot otherwise tell which one
 * answered, and an attacker who controls one of them can have a code issued by an honest one
 * delivered to it — the mix-up attack. The parameter costs a query string append and closes
 * it; the MCP specification asks for it today and says it expects to require it.
 *
 * league emits no `iss`, so it is added here rather than by replacing the response type.
 * `authorization_response_iss_parameter_supported` is advertised alongside, because a client
 * that sees the claim and no parameter is told to reject the response.
 *
 * Only the response that goes BACK TO THE CLIENT gets it. The first leg of the flow also
 * redirects — to the consent screen, on our own site — and that is not an authorization
 * response; the presence of `code` or `error` is what tells the two apart, and it is exactly
 * the condition RFC 9207 defines the parameter for.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class AuthorizationResponseIssuerListener
{
    public function __construct(
        private McpResource $resource,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if ('oauth2_authorize' !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        $response = $event->getResponse();
        $location = $response->headers->get('Location');

        if (!$response->isRedirect() || null === $location) {
            return;
        }

        $query = parse_url($location, \PHP_URL_QUERY);
        if (!\is_string($query)) {
            return;
        }

        parse_str($query, $parameters);
        if (!isset($parameters['code']) && !isset($parameters['error'])) {
            return;
        }

        if (isset($parameters['iss'])) {
            return;
        }

        $response->headers->set('Location', $location.'&iss='.rawurlencode($this->resource->issuer()));
    }
}
