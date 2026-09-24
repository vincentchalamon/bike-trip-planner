<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The 401 that tells an MCP client where to get a token (ADR-079).
 *
 * This is the first thing an agent ever receives from this server, and the whole
 * authorization flow hangs off one header parameter: `resource_metadata` names the document
 * that names the authorization server. The bundle's own entry point emits a bare
 * `WWW-Authenticate: Bearer` ({@see \League\Bundle\OAuth2ServerBundle\Security\Authenticator\OAuth2Authenticator::start()}),
 * which tells a conforming client nothing it can act on.
 *
 * `scope` is advertised alongside, so a client asks for what it needs rather than for
 * everything the server supports.
 */
final readonly class McpAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private McpResource $resource,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): JsonResponse
    {
        return self::unauthorized($this->resource);
    }

    /**
     * Shared with the listener that normalises what the authenticator itself returns, so the
     * two cannot drift into answering differently.
     */
    public static function unauthorized(McpResource $resource): JsonResponse
    {
        $response = new JsonResponse([
            'error' => 'invalid_token',
            'error_description' => 'A valid access token for this resource is required.',
        ], Response::HTTP_UNAUTHORIZED);

        $response->headers->set('WWW-Authenticate', \sprintf(
            'Bearer resource_metadata="%s/.well-known/oauth-protected-resource/mcp", scope="%s"',
            $resource->issuer(),
            implode(' ', McpResource::SCOPES),
        ));

        return $response;
    }
}
