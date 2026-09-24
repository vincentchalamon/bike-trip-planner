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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The programme's hard prerequisite, asserted per tool rather than once.
 *
 * On the MCP path a refusal and an absence must be **the same answer, byte for byte**.
 * Otherwise a caller learns which identifiers exist, which is the UUID oracle ADR-038 closed
 * on HTTP and which a change of transport reopens: `HideForbiddenAsNotFoundListener` runs on
 * `kernel.exception`, and the MCP SDK converts the exception to a JSON-RPC error before the
 * kernel ever sees it.
 *
 * What makes it hold is not a listener but the shape of the expression. Naming the URI
 * variable evaluates at `pre_read`, so `TripVoter` refuses an unknown trip in exactly the way
 * it refuses someone else's, before any provider can report which case it was.
 * {@see \App\Tests\Integration\Mcp\McpToolContractTest::noToolAuthorizesThroughTheLoadedObject}
 * keeps new tools in that shape; this one proves the shape does what it claims.
 *
 * Every tool that takes an identifier belongs here. A tool added without a row is a tool
 * nobody checked.
 */
#[ResetDatabase]
final class McpIndistinguishabilityTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    private const string PROTOCOL_VERSION = '2026-07-28';

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009a1';

    private const string STAGE_ID = '01936f6e-0000-7000-8000-0000000009b1';

    private const string UNKNOWN_TRIP_ID = '01936f6e-0000-7000-8000-0000000009ff';

    private const string UNKNOWN_STAGE_ID = '01936f6e-0000-7000-8000-0000000009fe';

    private const string TRIP_TITLE = 'Traversée du Vercors';

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private Client $client;

    private User $owner;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt('owner@example.com');
    }

    /**
     * @return iterable<string, array{string, array<string, string>, array<string, string>}>
     */
    public static function tools(): iterable
    {
        yield 'get_trip' => [
            'get_trip',
            ['id' => self::TRIP_ID],
            ['id' => self::UNKNOWN_TRIP_ID],
        ];

        yield 'get_stage' => [
            'get_stage',
            ['tripId' => self::TRIP_ID, 'stageId' => self::STAGE_ID],
            ['tripId' => self::UNKNOWN_TRIP_ID, 'stageId' => self::UNKNOWN_STAGE_ID],
        ];
    }

    /**
     * @param array<string, string> $someoneElses arguments naming a record that exists, owned by another user
     * @param array<string, string> $nothing      arguments naming a record that does not exist
     */
    #[Test]
    #[DataProvider('tools')]
    public function aRefusalAndAnAbsenceAreTheSameAnswer(string $tool, array $someoneElses, array $nothing): void
    {
        $this->seedTrip();
        $intruder = $this->createTestUserWithJwt('intruder@example.com')['user'];
        $token = $this->issueAccessTokenFor($intruder);

        $denied = $this->call($tool, $someoneElses, $token);
        $unknown = $this->call($tool, $nothing, $token);

        self::assertSame($denied, $unknown, \sprintf('"%s" tells a refusal apart from an absence.', $tool));

        // And neither says anything about what it refused to serve.
        self::assertStringNotContainsString(self::TRIP_ID, $denied);
        self::assertStringNotContainsString(self::STAGE_ID, $denied);
        self::assertStringNotContainsString(self::TRIP_TITLE, $denied);

        // Nor is an ownership refusal reported as a missing permission: the caller would go
        // and ask for a scope that would change nothing.
        self::assertStringNotContainsString('insufficient_scope', $denied);
    }

    /**
     * Guards the two above: if the seeded record were not actually readable by its owner, both
     * halves would refuse for a trivial reason and the comparison would prove nothing. The
     * lesson of the lot G voter bug — always assert that the owner gets through.
     *
     * @param array<string, string> $someoneElses
     * @param array<string, string> $nothing
     */
    #[Test]
    #[DataProvider('tools')]
    public function theOwnerStillGetsThrough(string $tool, array $someoneElses, array $nothing): void
    {
        $this->seedTrip();

        $token = $this->issueAccessTokenFor($this->owner);

        self::assertStringNotContainsString('"error"', $this->call($tool, $someoneElses, $token), \sprintf('The owner cannot read their own record through "%s".', $tool));

        // And the unknown identifiers really are unknown, or the comparison above would be
        // between two answers that both happen to succeed.
        self::assertStringContainsString('"error"', $this->call($tool, $nothing, $token));
    }

    /**
     * The exclusion table keeps `GET /trips/{id}/route` out of the tool surface because a
     * polyline is an artefact of a map: the gesture has no textual equivalent, and a long day's
     * trail is a context bomb that answers no question a model can act on. `get_stage` is the
     * drill-down, so letting the same points back in through it would be the same cost by
     * another door — which is exactly what `StageResponse` would have done, since it carries
     * `geometry` for the frontend.
     *
     * Absent rather than empty: a `geometry: []` would claim the day has no route.
     */
    #[Test]
    public function theDrillDownDoesNotServeTheCoordinateTrail(): void
    {
        $this->seedTrip();

        $answer = $this->call(
            'get_stage',
            ['tripId' => self::TRIP_ID, 'stageId' => self::STAGE_ID],
            $this->issueAccessTokenFor($this->owner),
        );

        self::assertStringNotContainsString('geometry', $answer);
        // And the day itself did come back, or the assertion above holds for the wrong reason.
        self::assertStringContainsString('dayNumber', $answer);
    }

    /**
     * @param array<string, string> $arguments
     */
    private function call(string $tool, array $arguments, string $bearer): string
    {
        $response = $this->request($tool, $arguments, $bearer);

        return json_encode($response->toArray(false), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, string> $arguments
     */
    private function request(string $tool, array $arguments, string $bearer): ResponseInterface
    {
        return $this->client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => $tool,
                'Authorization' => 'Bearer '.$bearer,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => $tool,
                    'arguments' => $arguments,
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    ],
                ],
            ],
        ]);
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
            id: self::STAGE_ID,
        )]);
        $repo->storeStatus(self::TRIP_ID, 'ready');
    }
}
