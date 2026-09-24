<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\OAuth\McpResource;
use App\Security\OAuth\McpToolScopes;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tells a missing scope apart from a missing trip (ADR-079, amending ADR-038).
 *
 * Two refusals must not leave as the same answer. A caller whose token lacks a permission
 * has to be told which one, or it can never ask for the right thing — the MCP specification
 * requires 403 with `insufficient_scope`. A caller asking about someone else's trip must get
 * exactly what it would get for a trip that does not exist, or the endpoint becomes a UUID
 * oracle, which is what ADR-038 exists to prevent.
 *
 * ⚠ The check runs BEFORE the MCP server, on `kernel.request`, and that is not a stylistic
 * choice. Measured: an `AccessDeniedException` raised by a tool's `security:` expression is
 * caught INSIDE the MCP SDK and returned as a JSON-RPC error (-32603, HTTP 400). It never
 * reaches `kernel.exception`, so neither this listener nor
 * {@see HideForbiddenAsNotFoundListener} would see it there. Deciding beforehand also means
 * a call with the wrong scope runs no provider at all, which is what matters the day a tool
 * writes something.
 *
 * Because it decides first, the tools' `security:` expressions stay about ownership alone —
 * the scope is not repeated in them, and {@see McpToolScopes} is the single source of truth.
 * A tool whose scope is not declared would be callable by any token, which is why the
 * coverage test exists.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class McpInsufficientScopeListener
{
    public function __construct(
        private McpToolScopes $toolScopes,
        private McpResource $resource,
        private Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/mcp')) {
            return;
        }

        // The addressed element's name, which the 2026-07-28 revision mirrors into a header.
        // Reading it rather than the JSON-RPC body is safe: the SDK refuses a call whose
        // header and body disagree (-32020) before any tool runs.
        $scope = $this->toolScopes->requiredBy((string) $request->headers->get('Mcp-Name', ''));

        if (null === $scope || $this->security->isGranted(McpToolScopes::role($scope))) {
            return;
        }

        $response = new JsonResponse([
            'error' => 'insufficient_scope',
            'error_description' => 'The access token does not carry the scope this tool requires.',
        ], Response::HTTP_FORBIDDEN);

        $response->headers->set('WWW-Authenticate', \sprintf(
            'Bearer error="insufficient_scope", scope="%s", resource_metadata="%s/.well-known/oauth-protected-resource/mcp"',
            $scope,
            $this->resource->issuer(),
        ));

        $event->setResponse($response);
    }
}
