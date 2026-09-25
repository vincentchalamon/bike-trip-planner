<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Entity\User;
use App\Tests\ApiTestCase;
use App\Tests\Functional\OAuth\IssuesOAuthTokensTrait;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * What a token is shown, which is not the same question as what it may call.
 *
 * The scope is enforced by a listener reading `Mcp-Name`, and `tools/list` names no tool — so
 * every token was shown every tool. A read-only agent would be handed nine tools that write,
 * try them, and be refused one at a time. Filtering a listing is not enforcement; it is the
 * difference between a surface an agent can reason about and a list two thirds of which are
 * traps.
 */
#[ResetDatabase]
final class McpToolsListTest extends ApiTestCase
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

        $this->client = self::createClient();
        ['user' => $this->user] = $this->createTestUserWithJwt('agent@example.com');
    }

    /** @var list<string> */
    private const array READS = ['list_trips', 'get_trip', 'get_stage', 'search_places'];

    /** @var list<string> */
    private const array WRITES = [
        'create_trip', 'update_trip_settings', 'edit_stages', 'add_waypoint', 'choose_accommodation',
        'analyze_trip', 'share_trip', 'unshare_trip', 'delete_trip',
    ];

    #[Test]
    public function aReadOnlyTokenIsShownNoToolThatWrites(): void
    {
        $names = $this->toolNames(['trips:read']);

        foreach (self::READS as $read) {
            self::assertContains($read, $names);
        }

        foreach (self::WRITES as $writes) {
            self::assertNotContains($writes, $names, \sprintf('`%s` is offered to a token that cannot call it.', $writes));
        }
    }

    /**
     * The whole surface, named.
     *
     * Asserted as an exact set rather than a series of `assertContains`: a tool that quietly
     * stops being listed is invisible from the inside — the server answers calls to it perfectly
     * well, and no agent ever finds it. The count is the unit's own claim, thirteen tools over
     * fifty routed operations, and it should not drift without someone deciding that it should.
     */
    #[Test]
    public function aWritingTokenIsShownTheWholeSurface(): void
    {
        $names = $this->toolNames(['trips:read', 'trips:write']);
        sort($names);

        $expected = array_merge(self::READS, self::WRITES);
        sort($expected);

        self::assertSame($expected, $names);
    }

    /**
     * @param list<string> $scopes
     *
     * @return list<string>
     */
    private function toolNames(array $scopes): array
    {
        $bearer = $this->issueAccessTokenFor($this->user, $scopes);

        $envelope = $this->client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => 'tools/list',
                'Authorization' => 'Bearer '.$bearer,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    ],
                ],
            ],
        ])->toArray(false);

        self::assertArrayNotHasKey('error', $envelope, json_encode($envelope, \JSON_THROW_ON_ERROR));

        $tools = $envelope['result']['tools'] ?? null;
        self::assertIsArray($tools);

        $names = [];
        foreach ($tools as $tool) {
            self::assertIsArray($tool);
            self::assertIsString($tool['name'] ?? null);
            $names[] = $tool['name'];
        }

        return $names;
    }
}
