<?php

declare(strict_types=1);

namespace App\Tests\Functional\OAuth;

use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * What a client reads before it has anything (ADR-079).
 *
 * These two documents are the entry point of the whole flow: unreachable, nothing starts;
 * wrong, a conforming client refuses to proceed. Both are anonymous by necessity.
 */
#[ResetDatabase]
final class DiscoveryTest extends ApiTestCase
{
    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    /**
     * @return iterable<string, array{string}>
     */
    public static function protectedResourcePaths(): iterable
    {
        // A client tries the resource's own path first and the root second. Serving only one
        // means its first probe 404s.
        yield 'path-inserted' => ['/.well-known/oauth-protected-resource/mcp'];
        yield 'at the root' => ['/.well-known/oauth-protected-resource'];
    }

    #[Test]
    #[DataProvider('protectedResourcePaths')]
    public function theProtectedResourceIsDescribedAnonymously(string $path): void
    {
        $response = self::createClient()->request('GET', $path);

        self::assertResponseIsSuccessful();
        $document = $response->toArray(false);

        self::assertSame('https://localhost/mcp', $document['resource']);
        // RFC 9728 requires at least one, and it is the only thing pointing at the server.
        self::assertSame(['https://localhost'], $document['authorization_servers']);
        self::assertSame(['trips:read', 'trips:write'], $document['scopes_supported']);
    }

    #[Test]
    public function theAuthorizationServerDescribesItselfAnonymously(): void
    {
        $response = self::createClient()->request('GET', '/.well-known/oauth-authorization-server');

        self::assertResponseIsSuccessful();
        $document = $response->toArray(false);

        self::assertSame('https://localhost', $document['issuer']);
        self::assertSame('https://localhost/oauth/authorize', $document['authorization_endpoint']);
        self::assertSame('https://localhost/oauth/token', $document['token_endpoint']);
        // Without this field a conforming MCP client refuses to proceed: there is no other
        // way for it to learn that PKCE is supported.
        self::assertSame(['S256'], $document['code_challenge_methods_supported']);
        self::assertTrue($document['client_id_metadata_document_supported']);
        self::assertTrue($document['authorization_response_iss_parameter_supported']);
        self::assertSame(['authorization_code', 'refresh_token'], $document['grant_types_supported']);
    }

    /**
     * The issuer is a deployment constant, not something the caller can influence: a client
     * validates it against the URL it built this address from, so a forged Host would mean a
     * forged identity for the whole flow.
     */
    #[Test]
    public function theIssuerDoesNotFollowTheHostHeader(): void
    {
        $response = self::createClient()->request('GET', '/.well-known/oauth-authorization-server', [
            'headers' => ['Host' => 'attacker.example'],
        ]);

        self::assertSame('https://localhost', $response->toArray(false)['issuer']);
    }
}
