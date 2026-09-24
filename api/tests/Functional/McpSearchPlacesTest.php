<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\HttpClient\MockHttpClient;
use ApiPlatform\Test\Client;
use App\Entity\User;
use App\Tests\ApiTestCase;
use App\Tests\Functional\OAuth\IssuesOAuthTokensTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * `search_places`: the same provider as `GET /geocode/search`, reached with arguments.
 *
 * The HTTP side is covered by {@see GeocodeTest}; what is asserted here is that porting the
 * search out of a controller did not fork its behaviour — one provider, two doors — and that
 * a missing argument is reported rather than answered with an empty list, which a model would
 * read as "no such place".
 */
#[ResetDatabase]
final class McpSearchPlacesTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    private Client $client;

    private User $user;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();
        self::getContainer()->get('cache.osm')->clear();

        $this->client = self::createClient();
        ['user' => $this->user] = $this->createTestUserWithJwt('searcher@example.com');

        $this->mockNominatim();
    }

    /** The same provider, reached with arguments instead of a query string. */
    #[Test]
    public function theToolAnswersTheSamePlaces(): void
    {
        $envelope = $this->callTool(['q' => 'Grenoble'])->toArray(false);

        self::assertArrayNotHasKey('error', $envelope, json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        $structured = $envelope['result']['structuredContent'] ?? null;
        self::assertIsArray($structured);
        $members = $structured['member'] ?? null;
        self::assertIsArray($members);
        self::assertIsArray($members[0] ?? null);
        self::assertSame('Grenoble', $members[0]['name']);
    }

    /**
     * Without a search term the tool must say so rather than answer an empty list: a model
     * given nothing back concludes the place does not exist and moves on.
     */
    #[Test]
    public function theToolSaysWhenTheSearchTermIsMissing(): void
    {
        $envelope = $this->callTool([])->toArray(false);

        self::assertArrayHasKey('error', $envelope);
        self::assertStringContainsString('q', json_encode($envelope, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callTool(array $arguments): ResponseInterface
    {
        return $this->client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => 'search_places',
                'Authorization' => 'Bearer '.$this->issueAccessTokenFor($this->user),
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'search_places',
                    'arguments' => $arguments,
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    ],
                ],
            ],
        ]);
    }

    private function mockNominatim(): void
    {
        self::getContainer()->set('nominatim.client', new MockHttpClient(
            static fn (): MockResponse => new MockResponse(json_encode([[
                'name' => 'Grenoble',
                'display_name' => 'Grenoble, Isère, France',
                'lat' => '45.1885',
                'lon' => '5.7245',
                'addresstype' => 'city',
            ]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]),
        ));
    }
}
