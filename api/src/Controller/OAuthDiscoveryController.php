<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\OAuth\McpResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * How an MCP client finds the authorization server, and what it may assume about it
 * (ADR-079).
 *
 * Plain JSON from a plain Symfony route rather than an API Platform resource: the shapes are
 * fixed by RFC 9728 and RFC 8414, they carry no JSON-LD, and they are the first thing a
 * client reads — before it has a token, before it knows anything.
 *
 * Both documents are derived from DEFAULT_URI, never from the incoming Host header. A client
 * validates that the `issuer` it reads equals the URL it built the well-known address from,
 * so that value decides whether the whole flow is trusted; deriving it from a request header
 * would put a security-relevant identity in the hand of whoever is asking. The router already
 * builds absolute URLs from this variable.
 */
final readonly class OAuthDiscoveryController
{
    public function __construct(
        private McpResource $resource,
    ) {
    }

    /**
     * Served at the path the specification's fallback tries first — the resource's own path
     * inserted after the suffix — and at the root, which it tries second. Both describe the
     * same resource; serving only one means a conforming client's first probe 404s.
     */
    #[Route('/.well-known/oauth-protected-resource/mcp', methods: ['GET'], priority: 10)]
    #[Route('/.well-known/oauth-protected-resource', methods: ['GET'], priority: 10)]
    public function protectedResource(): JsonResponse
    {
        return $this->document([
            'resource' => $this->resource->canonicalUri(),
            // RFC 9728 requires at least one, and it is what tells the client where to go.
            'authorization_servers' => [$this->resource->issuer()],
            'scopes_supported' => McpResource::SCOPES,
            'bearer_methods_supported' => ['header'],
        ]);
    }

    #[Route('/.well-known/oauth-authorization-server', methods: ['GET'], priority: 10)]
    public function authorizationServer(): JsonResponse
    {
        return $this->document([
            'issuer' => $this->resource->issuer(),
            'authorization_endpoint' => $this->resource->issuer().'/oauth/authorize',
            'token_endpoint' => $this->resource->issuer().'/oauth/token',
            'scopes_supported' => McpResource::SCOPES,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            // Not optional. A conforming MCP client REFUSES TO PROCEED when this field is
            // absent, because there is no other way to discover that PKCE is supported.
            'code_challenge_methods_supported' => ['S256'],
            // Public clients only: there is no secret to present.
            'token_endpoint_auth_methods_supported' => ['none'],
            // What tells a client it may use an HTTPS URL as its client_id instead of
            // registering (RFC 7591 dynamic registration is deprecated and not implemented).
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function document(array $document): JsonResponse
    {
        $response = new JsonResponse($document);
        // Read by clients that have no credentials yet, from other origins, and stable for
        // as long as the deployment is.
        $response->headers->set('Cache-Control', 'public, max-age=3600');
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }
}
