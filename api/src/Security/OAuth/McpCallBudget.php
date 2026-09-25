<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * How much an agent may ask for: counted per call, not per HTTP request.
 *
 * An agent loop is the likeliest source of load this system will see. It is bounded where the
 * scope is judged, and for the same reason ({@see McpScopeGuard}): the SDK invokes the handler
 * once per parsed message, and on the handshake era one `POST /mcp` can carry a batch of up to a
 * hundred. A limiter on the HTTP request would be divided by that much.
 *
 * Two budgets, both keyed on the user AND the OAuth client — so two agents of one person get two
 * buckets, and one agent serving two people does too:
 *
 *  - `mcp_tool_call` counts every call. It is sized for the loop the design prescribes rather
 *    than against it: `create_trip` returns at once and the agent is told to poll `get_trip`
 *    (ADR-080), so one ordinary task is a write, ten to fifteen polls and a drill-down per day.
 *    A budget that refused that would punish the behaviour the server asks for;
 *  - `mcp_mutation` counts the calls that write, on top. It sits above `limiter.trip_create`,
 *    which still bounds creation — and through it the third-party fetches — on its own.
 *
 * A refusal is a JSON-RPC error carrying the delay: at this depth the SDK writes the HTTP
 * response, so there is no `Retry-After` header to set — which is also why the refusal is
 * returned rather than thrown.
 *
 * @implements RequestHandlerInterface<CallToolResult|ReadResourceResult>
 */
final readonly class McpCallBudget implements RequestHandlerInterface
{
    /**
     * @param RequestHandlerInterface<CallToolResult|ReadResourceResult> $inner
     */
    public function __construct(
        private RequestHandlerInterface $inner,
        private McpToolScopes $toolScopes,
        private TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'limiter.mcp_tool_call')]
        private RateLimiterFactory $calls,
        #[Autowire(service: 'limiter.mcp_mutation')]
        private RateLimiterFactory $mutations,
    ) {
    }

    /**
     * One bucket per person and per agent. Public so a test can drain the right one.
     */
    public static function key(string $userIdentifier, string $clientId): string
    {
        return $userIdentifier.'|'.$clientId;
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

        if (null === $name) {
            return $this->inner->handle($request, $session);
        }

        $key = $this->caller();

        $limit = $this->calls->create($key)->consume();
        if ($limit->isAccepted() && 'trips:write' === $this->toolScopes->requiredBy($name)) {
            $limit = $this->mutations->create($key)->consume();
        }

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return new Error(
                $request->getId(),
                Error::SERVER_ERROR,
                \sprintf('rate_limited: too many calls from this agent. Retry in %d seconds.', $retryAfter),
                ['error' => 'rate_limited', 'retryAfter' => $retryAfter],
            );
        }

        return $this->inner->handle($request, $session);
    }

    private function caller(): string
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        return self::key(
            $user instanceof UserInterface ? $user->getUserIdentifier() : 'anonymous',
            $token instanceof OAuth2Token ? $token->getOAuthClientId() : 'unknown',
        );
    }
}
