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
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The MCP endpoint, exercised as what it is: a plain Symfony route carrying JSON-RPC.
 *
 * No MCP client is needed to test a tool, and none is used here. What this pins is that a
 * tool is an API Platform operation and goes through the same provider and the same
 * `security:` expression as the HTTP operation next to it (ADR-063, ADR-064) — the
 * authorization is enforced at call time, not merely used to filter `tools/list`.
 */
#[ResetDatabase]
final class McpToolCallTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009a1';

    private const string TRIP_TITLE = 'Traversée du Vercors';

    private const string PROTOCOL_VERSION = '2026-07-28';

    private Client $client;

    private User $owner;

    private string $ownerToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->owner, 'token' => $this->ownerToken] = $this->createTestUserWithJwt('owner@example.com');
    }

    #[Test]
    public function theEndpointRefusesAnAnonymousCaller(): void
    {
        $this->client->request('POST', '/mcp', [
            'headers' => $this->protocolHeaders('tools/list'),
            'json' => $this->rpc('tools/list'),
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function theToolAnswersItsOwner(): void
    {
        $this->seedTrip();

        $response = $this->client->request('POST', '/mcp', [
            'headers' => array_merge(
                $this->protocolHeaders('tools/call', 'get_trip'),
                $this->authHeader($this->ownerToken),
            ),
            'json' => $this->rpc('tools/call', ['name' => 'get_trip', 'arguments' => ['id' => self::TRIP_ID]]),
        ]);

        self::assertResponseIsSuccessful();

        $payload = $response->toArray(false);
        self::assertArrayNotHasKey('error', $payload);

        // JSON_UNESCAPED_UNICODE, or the accented title comes back as é escapes and the
        // assertions below compare against something the payload never literally contains.
        $body = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString(self::TRIP_ID, $body);
        // Pins the positive side, so the denial below cannot pass vacuously.
        self::assertStringContainsString(self::TRIP_TITLE, $body);
    }

    #[Test]
    public function theToolRefusesANonOwner(): void
    {
        $this->seedTrip();
        ['token' => $intruderToken] = $this->createTestUserWithJwt('intruder@example.com');

        $response = $this->client->request('POST', '/mcp', [
            'headers' => array_merge(
                $this->protocolHeaders('tools/call', 'get_trip'),
                $this->authHeader($intruderToken),
            ),
            'json' => $this->rpc('tools/call', ['name' => 'get_trip', 'arguments' => ['id' => self::TRIP_ID]]),
        ]);

        self::assertStringNotContainsString(
            self::TRIP_TITLE,
            json_encode($response->toArray(false), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * The 2026-07-28 revision mirrors the protocol version, the method and the addressed
     * element name into headers, on top of the JSON-RPC body — so an edge route or a cache
     * can act on a call without parsing it. Omitting any of them is answered with -32020
     * (HeaderMismatch), one at a time.
     *
     * @return array<string, string>
     */
    private function protocolHeaders(string $method, ?string $name = null): array
    {
        return array_filter([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            'Mcp-Method' => $method,
            'Mcp-Name' => $name,
        ]);
    }

    /**
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
