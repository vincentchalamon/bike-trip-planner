<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Contracts\HttpClient\ResponseInterface;
use ApiPlatform\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use App\Tests\ApiTestCase;
use App\Tests\Functional\OAuth\IssuesOAuthTokensTrait;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The MCP endpoint, exercised as what it is: a plain Symfony route carrying JSON-RPC.
 *
 * No MCP client is needed to test a tool, and none is used here. Two properties are pinned.
 * One is that a tool is an API Platform operation and goes through the same provider and the
 * same `security:` expression as the HTTP operation next to it (ADR-063, ADR-064), enforced
 * at call time rather than used to filter `tools/list`. The other is that the credential
 * this endpoint takes is an OAuth access token AND NOTHING ELSE — in particular not the JWT
 * the PWA carries, which is the whole reason the two token systems have separate keys
 * (ADR-079).
 */
#[ResetDatabase]
final class McpToolCallTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009a1';

    private const string TRIP_TITLE = 'Traversée du Vercors';

    private const string PROTOCOL_VERSION = '2026-07-28';

    private Client $client;

    private User $owner;

    private string $ownerSessionJwt;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner, 'token' => $this->ownerSessionJwt] = $this->createTestUserWithJwt('owner@example.com');
    }

    #[Test]
    public function anAnonymousCallerIsToldWhereToGetAToken(): void
    {
        $response = $this->call($this->rpc('tools/list'), 'tools/list');

        self::assertSame(401, $response->getStatusCode());

        $challenge = (string) ($response->getHeaders(false)['www-authenticate'][0] ?? '');
        // The one parameter the whole flow hangs off: it names the document that names the
        // authorization server. A bare `Bearer` tells a conforming client nothing.
        self::assertStringContainsString(
            'resource_metadata="https://localhost/.well-known/oauth-protected-resource/mcp"',
            $challenge,
        );
        self::assertStringContainsString('scope="trips:read trips:write"', $challenge);
    }

    /**
     * THE anti-passthrough test.
     *
     * A PWA session token and an agent access token are the same shape from the same issuer.
     * What tells them apart is the key that signed them, and that difference is one
     * environment variable away from disappearing — the bundle's own Flex recipe points both
     * key paths at Lexik's files. This is the assertion that would go red if it did.
     */
    #[Test]
    public function aPwaSessionTokenOpensNothingHere(): void
    {
        $response = $this->call($this->rpc('tools/list'), 'tools/list', $this->ownerSessionJwt);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function theToolAnswersItsOwner(): void
    {
        $this->seedTrip();
        $accessToken = $this->issueAccessTokenFor($this->owner);

        $response = $this->call(
            $this->rpc('tools/call', ['name' => 'get_trip', 'arguments' => ['id' => self::TRIP_ID]]),
            'tools/call',
            $accessToken,
            'get_trip',
        );

        // On the response object, not assertResponseStatusCodeSame(): issuing the token
        // creates a second client, and the assertion helpers look at the last one CREATED
        // rather than the last one used — which made this assert the token exchange.
        self::assertSame(200, $response->getStatusCode());

        $payload = $response->toArray(false);
        self::assertArrayNotHasKey('error', $payload);

        // JSON_UNESCAPED_UNICODE, or the accented title comes back as escapes and the
        // assertions below compare against something the payload never literally contains.
        $body = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString(self::TRIP_ID, $body);
        // Pins the positive side, so the denial below cannot pass vacuously.
        self::assertStringContainsString(self::TRIP_TITLE, $body);
    }

    /**
     * A missing permission is said out loud, with the scope that is missing: a client that
     * is only told "no" can never ask for the right thing. The MCP specification requires
     * 403 here, which is precisely what ADR-038 turns into a 404 everywhere else.
     *
     * No write tool is needed to prove it — a token carrying only the write scope calling a
     * read tool is the same situation from the other side.
     */
    #[Test]
    public function aTokenWithoutTheScopeIsToldWhichOneIsMissing(): void
    {
        $this->seedTrip();
        $accessToken = $this->issueAccessTokenFor($this->owner, ['trips:write']);

        $response = $this->call(
            $this->rpc('tools/call', ['name' => 'get_trip', 'arguments' => ['id' => self::TRIP_ID]]),
            'tools/call',
            $accessToken,
            'get_trip',
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('insufficient_scope', $response->toArray(false)['error'] ?? null);

        $challenge = (string) ($response->getHeaders(false)['www-authenticate'][0] ?? '');
        self::assertStringContainsString('error="insufficient_scope"', $challenge);
        self::assertStringContainsString('scope="trips:read"', $challenge);
        self::assertStringContainsString('resource_metadata=', $challenge);
    }

    /**
     * The prerequisite the programme names as non-negotiable: on the MCP path, someone
     * else's trip and a trip that does not exist must be INDISTINGUISHABLE. Otherwise the
     * endpoint answers "this id exists, it is just not yours" — the UUID oracle ADR-038 was
     * written to close, reopened by a change of transport.
     *
     * Worth being precise about WHY it holds, because it is not ADR-038's listener doing the
     * work: the MCP SDK catches the AccessDeniedException itself and answers a JSON-RPC
     * error, so `kernel.exception` never sees it. Both cases come out identical because
     * TripVoter refuses an unknown trip exactly as it refuses someone else's — the refusal
     * happens before anything can report that the trip is missing. The assertions below pin
     * both halves: the two answers are the same, and neither names the id or the title.
     */
    #[Test]
    public function anotherUsersTripIsIndistinguishableFromNoTripAtAll(): void
    {
        $this->seedTrip();
        $intruder = $this->createTestUserWithJwt('intruder@example.com')['user'];
        $accessToken = $this->issueAccessTokenFor($intruder);

        $denied = $this->body($this->call(
            $this->rpc('tools/call', ['name' => 'get_trip', 'arguments' => ['id' => self::TRIP_ID]]),
            'tools/call',
            $accessToken,
            'get_trip',
        ));

        $unknown = $this->body($this->call(
            $this->rpc('tools/call', ['name' => 'get_trip', 'arguments' => ['id' => '01936f6e-0000-7000-8000-0000000009ff']]),
            'tools/call',
            $accessToken,
            'get_trip',
        ));

        self::assertSame($denied, $unknown);
        // Nothing in the answer distinguishes a trip that exists from one that does not.
        self::assertStringNotContainsString(self::TRIP_ID, $denied);
        self::assertStringNotContainsString(self::TRIP_TITLE, $denied);
        // And an ownership refusal is not reported as a missing permission either, or the
        // client would go and ask for a scope that would change nothing.
        self::assertStringNotContainsString('insufficient_scope', $denied);
    }

    #[Test]
    public function theToolRefusesANonOwner(): void
    {
        $this->seedTrip();
        $intruder = $this->createTestUserWithJwt('intruder@example.com')['user'];
        $accessToken = $this->issueAccessTokenFor($intruder);

        $response = $this->call(
            $this->rpc('tools/call', ['name' => 'get_trip', 'arguments' => ['id' => self::TRIP_ID]]),
            'tools/call',
            $accessToken,
            'get_trip',
        );

        self::assertStringNotContainsString(
            self::TRIP_TITLE,
            json_encode($response->toArray(false), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * The whole answer, so two refusals can be compared as the caller sees them rather than
     * on a field somebody chose to look at.
     */
    private function body(ResponseInterface $response): string
    {
        return json_encode($response->toArray(false), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function call(array $body, string $method, ?string $bearer = null, ?string $name = null): ResponseInterface
    {
        $headers = array_filter([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            'Mcp-Method' => $method,
            'Mcp-Name' => $name,
            'Authorization' => null === $bearer ? null : 'Bearer '.$bearer,
        ]);

        return $this->client->request('POST', '/mcp', ['headers' => $headers, 'json' => $body]);
    }

    /**
     * The 2026-07-28 revision mirrors the protocol version, the method and the addressed
     * element name into headers, on top of the JSON-RPC body — so an edge route or a cache
     * can act on a call without parsing it. Omitting any of them is answered with -32020
     * (HeaderMismatch), one at a time.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params + [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
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
        $repo->storeTitle(self::TRIP_ID, self::TRIP_TITLE);
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
        $repo->storeStatus(self::TRIP_ID, 'ready');
    }
}
