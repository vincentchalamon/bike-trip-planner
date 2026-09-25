<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The authority on scope: it judges each message by what the SDK parsed out of the body.
 *
 * {@see \App\EventListener\McpInsufficientScopeListener} used to be the only enforcement, and it
 * reads the `Mcp-Name` header. Three measured ways past it, each exercised by
 * {@see \App\Tests\Functional\McpScopeEnforcementTest}:
 *
 *  - on the modern leg the SDK unwraps `=?base64?…?=` before checking the header against the
 *    body, so an encoded name satisfies the SDK and matches nothing in a verbatim lookup;
 *  - the SDK also serves the handshake era — any call that claims no modern revision lands
 *    there — and that leg validates no mirror header at all;
 *  - the same leg accepts a JSON-RPC batch, and one header cannot describe several calls.
 *
 * Here none of that matters: this decorates the handler the SDK invokes once per message, after
 * parsing, whatever the era, the encoding or the batching. The listener stays, because it is the
 * only place that can answer HTTP 403 with `WWW-Authenticate` — what the MCP specification
 * requires and what a client acts on. It answers the well-formed case; this refuses everything
 * it could not see.
 *
 * Both kinds of message the handler serves are judged — a tool call by its name, a resource
 * read by its URI. No `McpResource` exists today; the day one does, it is refused until its
 * scope is declared and {@see McpToolScopes} can find it by that URI, which is loud, rather
 * than served to any token, which is the bug this class exists to close.
 *
 * A declared element with no scope is refused rather than let through. The coverage test makes
 * that unreachable for tools today; if it ever were reached, open would be the wrong way to
 * fail.
 *
 * @implements RequestHandlerInterface<CallToolResult|ReadResourceResult>
 */
final readonly class McpScopeGuard implements RequestHandlerInterface
{
    /**
     * @param RequestHandlerInterface<CallToolResult|ReadResourceResult> $inner
     */
    public function __construct(
        private RequestHandlerInterface $inner,
        private McpToolScopes $toolScopes,
        private AuthorizationCheckerInterface $security,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $name = match (true) {
            $request instanceof CallToolRequest => $request->name,
            $request instanceof ReadResourceRequest => $request->uri,
            default => null,
        };

        if (null !== $name) {
            $scope = $this->toolScopes->requiredBy($name);

            if (null === $scope || !$this->security->isGranted(McpToolScopes::role($scope))) {
                return new Error(
                    $request->getId(),
                    Error::SERVER_ERROR,
                    'insufficient_scope: the access token does not carry the scope this call requires.',
                    ['error' => 'insufficient_scope', 'scope' => $scope],
                );
            }
        }

        return $this->inner->handle($request, $session);
    }
}
