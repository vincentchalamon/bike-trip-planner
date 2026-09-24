<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\HttpClient\MockHttpClient;
use ApiPlatform\Test\Client;
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

    private string $token;

    /** Asking for this makes the mocked Nominatim answer with a hostile name. */
    private const string NOISY_QUERY = 'noisy-place';

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();
        self::getContainer()->get('cache.osm')->clear();

        $this->client = self::createClient();
        ['user' => $user] = $this->createTestUserWithJwt('searcher@example.com');

        // Issued here, so that the only thing between installing the mock and the request is
        // the request: anything that touches the kernel in between drops the replacement and
        // the call silently goes to the real Nominatim. That is how `Mairie de Noisy-le-Grand`
        // turned up in a supposedly mocked assertion.
        $this->token = $this->issueAccessTokenFor($user);
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
     * The one tool marked `openWorldHint`, and so the one place a third party's strings build
     * the answer. Cleaned at that boundary rather than at a projection, which is why nothing
     * downstream has to remember to.
     *
     * Structural hygiene only — see ThirdPartyText, and unit 3C for the posture that reads
     * what it cleans.
     */
    #[Test]
    public function aPlaceNameCannotReshapeTheAnswer(): void
    {
        // A query of its own, because the 24-hour cache is keyed on it: reusing the one
        // another test warmed would serve that test's clean answer instead of this payload.
        $envelope = $this->callTool(['q' => self::NOISY_QUERY])->toArray(false);

        $members = $envelope['result']['structuredContent']['member'] ?? null;
        self::assertIsArray($members, json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
        self::assertIsArray($members[0] ?? null);

        self::assertSame('Gre noble', $members[0]['name'], 'A live Nominatim answer here means the mock was dropped before the request.');
        self::assertIsString($members[0]['displayName']);
        self::assertLessThanOrEqual(201, mb_strlen($members[0]['displayName']));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callTool(array $arguments): ResponseInterface
    {
        // The token above is obtained through several requests of its own, which initialize
        // `nominatim.client` — and TestContainer refuses to replace a service it has already
        // built. A fresh kernel gives one that has not been built yet, and nothing then runs
        // between the replacement and the call. Without this the mock is silently not
        // installed and the request goes to the live Nominatim: that is how
        // `Mairie de Noisy-le-Grand` turned up in a supposedly mocked assertion.
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->mockNominatim();

        return $this->client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => 'search_places',
                'Authorization' => 'Bearer '.$this->token,
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

    /**
     * Installed once, because the container refuses to replace a service it has already
     * initialized. What it answers is chosen by the query rather than by mutating the test,
     * so there is nothing to get out of order.
     */
    private function mockNominatim(): void
    {
        self::getContainer()->set('nominatim.client', new MockHttpClient(
            static function (string $method, string $url): MockResponse {
                $noisy = str_contains($url, self::NOISY_QUERY);

                return new MockResponse(json_encode([[
                    'name' => $noisy ? "Gre\nno\u{0007}ble" : 'Grenoble',
                    'display_name' => $noisy ? str_repeat('a', 400) : 'Grenoble, Isère, France',
                    'lat' => '45.1885',
                    'lon' => '5.7245',
                    'addresstype' => 'city',
                ]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
            },
        ));
    }
}
