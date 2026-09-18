<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * SPIKE — proves what the rest of the spike could only infer: that the `security`
 * expression of an MCP tool is enforced at CALL time, not merely used to filter the
 * `tools/list` view.
 *
 * Also answers the spike's open question (d): how do you functionally test an MCP tool?
 * Answer: you do not need an MCP client. The transport is a plain Symfony route, so
 * ApiTestCase can POST JSON-RPC at it like any other endpoint.
 */
#[ResetDatabase]
final class McpToolCallTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009a1';

    /**
     * SPIKE FINDING: the 2026-07-28 revision mirrors both the protocol version AND the
     * method into HEADERS, on top of the JSON-RPC body. Omitting either is answered with
     * -32020 (HeaderMismatch), one at a time: MCP-Protocol-Version, then Mcp-Method, then
     * Mcp-Name for the element being addressed. Mirroring them lets an edge route or cache
     * a call without parsing the JSON-RPC body.
     *
     * @return array<string, string>
     */
    private function protocolHeaders(string $method, ?string $name = null): array
    {
        return array_filter([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
            'Mcp-Name' => $name,
        ]);
    }

    private Client $client;

    private User $owner;

    private string $ownerToken;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

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
    public function theToolIsCallableByItsOwner(): void
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

        self::assertArrayNotHasKey('error', $payload, 'The owner must be allowed to call the tool.');
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString(self::TRIP_ID, $body);
        // Pins the positive side, so the denial test below cannot pass vacuously.
        self::assertStringContainsString('Detail test trip', $body, 'The owner must actually receive the trip payload.');
    }

    /**
     * THE test of the whole spike: a second authenticated user must not read a trip that
     * is not theirs through an MCP tool, even though tools/list happily advertises the
     * tool to them (the expression reads `object`, so listing cannot decide and defers).
     */
    #[Test]
    public function theToolIsDeniedToANonOwner(): void
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

        $body = json_encode($response->toArray(false), \JSON_THROW_ON_ERROR);
        fwrite(\STDERR, "\n[SPIKE] réponse intrus: ".substr($body, 0, 400)."\n");

        self::assertStringNotContainsString(
            'Detail test trip',
            $body,
            'A non-owner read another user\'s trip through the MCP tool: the security expression is NOT enforced at call time.',
        );
    }

    /**
     * SPIKE FINDING — ADR-038 (403 masked as 404) does NOT hold on the MCP path.
     * A non-owner gets "Access Denied." while an unknown id gets "not found": the two
     * are distinguishable, which is exactly the UUID enumeration ADR-038 closes on HTTP.
     */
    #[Test]
    public function theDenialIsDistinguishableFromAnUnknownTrip(): void
    {
        $this->seedTrip();
        ['token' => $intruderToken] = $this->createTestUserWithJwt('intruder2@example.com');

        $denied = $this->callAs($intruderToken, self::TRIP_ID);
        $unknown = $this->callAs($intruderToken, '01936f6e-0000-7000-8000-0000000009ff');

        fwrite(\STDERR, "\n[SPIKE] trip d'autrui : ".$denied."\n[SPIKE] trip inexistant: ".$unknown."\n");

        self::assertNotSame(
            $denied,
            $unknown,
            'Same error for both — ADR-038 masking would hold. (If this ever passes, the finding is obsolete.)',
        );
    }

    private function callAs(string $token, string $tripId): string
    {
        $response = $this->client->request('POST', '/mcp', [
            'headers' => array_merge($this->protocolHeaders('tools/call', 'get_trip'), $this->authHeader($token)),
            'json' => $this->rpc('tools/call', ['name' => 'get_trip', 'arguments' => ['id' => $tripId]]),
        ]);

        return (string) ($response->toArray(false)['error']['message'] ?? 'NO ERROR');
    }

    private function seedTrip(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        /** @var DoctrineTripRequestRepository $repo */
        $repo = self::getContainer()->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip(self::TRIP_ID, $request);
        $repo->storeTitle(self::TRIP_ID, 'Detail test trip');
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
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ],
            ],
        ];
    }
}
