<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\OAuth\McpResource;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Checks the `resource` an MCP client says it wants the token for (RFC 8707, ADR-079).
 *
 * The specification requires clients to send it on both the authorization and the token
 * request, naming the server they intend to use the token at. A server that accepted any
 * value would be issuing tokens it knows are meant for somewhere else.
 *
 * There is exactly one resource here, so the check is an equality rather than a lookup: a
 * value that is not ours is `invalid_target`, and no value at all is accepted because this
 * server has nowhere else the token could be meant for. The machinery for several resources
 * is not built, because there is no second one to build it against.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class ResourceParameterListener
{
    public function __construct(
        private McpResource $resource,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if (!\in_array($route, ['oauth2_authorize', 'oauth2_token'], true)) {
            return;
        }

        $requested = 'oauth2_token' === $route
            ? $request->request->get('resource')
            : $request->query->get('resource');

        if (null === $requested || '' === $requested) {
            return;
        }

        if ($this->resource->canonicalUri() === $requested) {
            return;
        }

        // RFC 8707 names this error; answering `invalid_request` would tell a client its
        // request was malformed when what is wrong is where it wants to go.
        $event->setResponse(new JsonResponse([
            'error' => 'invalid_target',
            'error_description' => 'This authorization server issues tokens for one resource, and it is not the one requested.',
        ], Response::HTTP_BAD_REQUEST));
    }
}
