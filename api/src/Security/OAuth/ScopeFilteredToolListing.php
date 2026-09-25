<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Symfony\Component\HttpFoundation\Request;
use ApiPlatform\Mcp\Security\ElementAccessCheckerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Keeps out of `tools/list` the tools the caller's token could not call anyway.
 *
 * The scope is enforced before the MCP server runs, by
 * {@see \App\EventListener\McpInsufficientScopeListener}, which reads the `Mcp-Name` header. A
 * `tools/list` names no tool, so that listener has nothing to check and every tool was listed
 * to every token — a read-only agent was shown nine tools that write, and would try them one
 * by one to be refused one by one. Filtering is not enforcement (ADR-064 §2, and
 * `AccessCheckerProvider` still runs on every call); it is the difference between a surface an
 * agent can reason about and a list where nine entries out of thirteen are traps.
 *
 * Decorates the vendor's expression checker rather than replacing it: that one hides the tools
 * whose `security:` expression the caller fails, which is a separate question from the scope
 * its token carries.
 */
#[AsDecorator(decorates: 'api_platform.mcp.security.expression_access_checker')]
final readonly class ScopeFilteredToolListing implements ElementAccessCheckerInterface
{
    public function __construct(
        private ElementAccessCheckerInterface $decorated,
        private McpToolScopes $scopes,
        private AuthorizationCheckerInterface $authorization,
        private RequestStack $requests,
    ) {
    }

    public function isGranted(string $operationName): bool
    {
        $scope = $this->scopes->requiredBy($operationName);

        // No request means no caller to protect — a console listing (`debug:mcp`) would
        // otherwise show nothing at all, since no token is anywhere in sight.
        if (null !== $scope && $this->requests->getCurrentRequest() instanceof Request && !$this->authorization->isGranted(McpToolScopes::role($scope))) {
            return false;
        }

        return $this->decorated->isGranted($operationName);
    }
}
