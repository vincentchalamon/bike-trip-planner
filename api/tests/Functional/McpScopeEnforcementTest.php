<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use App\Tests\ApiTestCase;
use App\Tests\Functional\OAuth\IssuesOAuthTokensTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * A read-only token cannot reach a tool that writes, whatever the envelope looks like.
 *
 * The scope used to be decided on the `Mcp-Name` header alone, looked up verbatim. Two things
 * the SDK does made that insufficient, and both are exercised here rather than described:
 *
 *  - on the modern leg it validates the header AFTER unwrapping the `=?base64?…?=` form, so a
 *    header naming `delete_trip` in base64 satisfies the SDK and matches no entry in a verbatim
 *    lookup;
 *  - it also serves the handshake era, where no mirror header is validated at all and a body
 *    may carry a JSON-RPC batch. A call that claims no modern revision lands there.
 *
 * `delete_trip` is the probe because its first call only answers a confirmation challenge:
 * whether the tool ran is visible in the answer, and nothing is deleted either way.
 */
#[ResetDatabase]
final class McpScopeEnforcementTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string MODERN = '2026-07-28';

    private const string HANDSHAKE = '2025-06-18';

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009d1';

    private Client $client;

    private User $owner;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();
        self::getContainer()->get('cache.mcp_confirmation')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt('owner@example.com');
    }

    #[Test]
    public function aToolNameWrappedInBase64DoesNotSlipPastTheScope(): void
    {
        $this->seedTrip();
        $readOnly = $this->issueAccessTokenFor($this->owner, ['trips:read']);

        $response = $this->client->request('POST', '/mcp', [
            'headers' => $this->modernHeaders($readOnly, '=?base64?'.base64_encode('delete_trip').'?='),
            'json' => $this->modernCall('delete_trip', ['id' => self::TRIP_ID]),
        ]);

        $this->assertRefusedForScope($response);
        // Decoded like the SDK decodes it, so this well-formed call still gets the answer the
        // specification requires rather than a bare JSON-RPC error.
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('error="insufficient_scope"', (string) ($response->getHeaders(false)['www-authenticate'][0] ?? ''));
    }

    #[Test]
    public function theHandshakeEraDoesNotSlipPastTheScope(): void
    {
        $this->seedTrip();
        $readOnly = $this->issueAccessTokenFor($this->owner, ['trips:read']);
        $session = $this->handshake($readOnly);

        $response = $this->client->request('POST', '/mcp', [
            'headers' => $this->handshakeHeaders($readOnly, $session),
            'json' => $this->handshakeCall(2, 'delete_trip', ['id' => self::TRIP_ID]),
        ]);

        $this->assertRefusedForScope($response);
    }

    #[Test]
    public function aBatchOnTheHandshakeEraIsJudgedMessageByMessage(): void
    {
        $this->seedTrip();
        $readOnly = $this->issueAccessTokenFor($this->owner, ['trips:read']);
        $session = $this->handshake($readOnly);

        $response = $this->client->request('POST', '/mcp', [
            'headers' => $this->handshakeHeaders($readOnly, $session),
            'json' => [
                $this->handshakeCall(2, 'get_trip', ['id' => self::TRIP_ID]),
                $this->handshakeCall(3, 'delete_trip', ['id' => self::TRIP_ID]),
            ],
        ]);

        $this->assertRefusedForScope($response);
        // Message by message, not the whole envelope: the read the token is entitled to still
        // answers, which is also what proves the refusal is not a blanket one.
        self::assertStringContainsString('TripDigest', $response->getContent(false));
    }

    /**
     * The positive side, so the refusals above cannot pass vacuously: the same handshake, the
     * same call, with a token that does carry the scope, reaches the tool.
     */
    #[Test]
    public function theHandshakeEraStillServesATokenThatCarriesTheScope(): void
    {
        $this->seedTrip();
        $writer = $this->issueAccessTokenFor($this->owner, ['trips:read', 'trips:write']);
        $session = $this->handshake($writer);

        $response = $this->client->request('POST', '/mcp', [
            'headers' => $this->handshakeHeaders($writer, $session),
            'json' => $this->handshakeCall(2, 'delete_trip', ['id' => self::TRIP_ID]),
        ]);

        self::assertStringContainsString('confirmationToken', $response->getContent(false));
    }

    private function assertRefusedForScope(ResponseInterface $response): void
    {
        $body = $response->getContent(false);

        // What would prove the tool ran: `delete_trip` answers its first call with a challenge.
        self::assertStringNotContainsString('confirmationToken', $body, 'A read-only token reached delete_trip: '.$body);
        self::assertStringContainsString('insufficient_scope', $body, 'The refusal must name the missing scope: '.$body);
    }

    private function handshake(string $bearer): string
    {
        $response = $this->client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
                'Authorization' => 'Bearer '.$bearer,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => self::HANDSHAKE,
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'probe', 'version' => '1.0.0'],
                ],
            ],
        ]);

        $session = $response->getHeaders(false)['mcp-session-id'][0] ?? null;
        self::assertIsString($session, 'The handshake era answered without a session: '.$response->getContent(false));

        $this->client->request('POST', '/mcp', [
            'headers' => $this->handshakeHeaders($bearer, $session),
            'json' => ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ]);

        return $session;
    }

    /**
     * @return array<string, string>
     */
    private function handshakeHeaders(string $bearer, string $session): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::HANDSHAKE,
            'Mcp-Session-Id' => $session,
            'Authorization' => 'Bearer '.$bearer,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function handshakeCall(int $id, string $name, array $arguments): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function modernHeaders(string $bearer, string $name): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'MCP-Protocol-Version' => self::MODERN,
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => $name,
            'Authorization' => 'Bearer '.$bearer,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function modernCall(string $name, array $arguments): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::MODERN,
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ],
            ],
        ];
    }

    private function seedTrip(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        /** @var DoctrineTripRequestRepository $repo */
        $repo = self::getContainer()->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip(self::TRIP_ID, $request);
        $repo->storeTitle(self::TRIP_ID, 'Traversée du Vercors');
        $repo->storeStatus(self::TRIP_ID, 'ready');
        $this->associateTripWithUser(self::TRIP_ID, $this->owner);

        $repo->storeStages(self::TRIP_ID, [new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1200.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.5, 6.5, 800.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0)],
        )]);
    }
}
