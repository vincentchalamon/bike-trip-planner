<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The authority on scope: it judges each tool call by the name the SDK parsed out of the body.
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
 * A declared tool with no scope is refused rather than let through. The coverage test makes
 * that unreachable today; if it ever were reached, open would be the wrong way to fail.
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
        private Security $security,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if ($request instanceof CallToolRequest) {
            $scope = $this->toolScopes->requiredBy($request->name);

            if (null === $scope || !$this->security->isGranted(McpToolScopes::role($scope))) {
                return new Error(
                    $request->getId(),
                    Error::SERVER_ERROR,
                    'insufficient_scope: the access token does not carry the scope this tool requires.',
                    ['error' => 'insufficient_scope', 'scope' => $scope],
                );
            }
        }

        return $this->inner->handle($request, $session);
    }
}
