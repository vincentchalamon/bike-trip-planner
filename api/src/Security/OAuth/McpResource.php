<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one resource this authorization server protects, and its one issuer (ADR-079).
 *
 * Both are constants of the deployment, and saying so in one place is the point: the
 * discovery documents, the `resource` parameter validation, the audience stamped into a
 * token and the audience checked at `/mcp` all have to agree, and an audience check that
 * disagrees with what was issued fails open or fails closed depending on which side drifted.
 *
 * Derived from DEFAULT_URI, never from a request header. A client validates the `issuer` it
 * reads against the URL it built the well-known address from; letting the caller influence
 * that value would hand it the identity the whole flow is anchored on.
 */
final readonly class McpResource
{
    /**
     * What a token may carry, and what the protected resource metadata advertises.
     *
     * Ownership still decides everything: a scope narrows what an agent may do with the
     * trips its user owns, it never grants access to anyone else's (ADR-063).
     */
    public const array SCOPES = ['trips:read', 'trips:write'];

    public function __construct(
        #[Autowire(env: 'DEFAULT_URI')]
        private string $defaultUri,
    ) {
    }

    public function issuer(): string
    {
        return rtrim($this->defaultUri, '/');
    }

    /**
     * The canonical URI of the MCP server, in the RFC 8707 sense: what a client puts in
     * `resource`, and what a token has to be permitted for.
     */
    public function canonicalUri(): string
    {
        return $this->issuer().'/mcp';
    }
}
