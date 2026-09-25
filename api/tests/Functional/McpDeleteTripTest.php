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
 * The two remaining body-less tools: the irreversible one and the one that starts work.
 *
 * `delete_trip` is where the confirmation matters most, and where the impact summary has to be
 * recognisable — a person reading the transcript must be able to tell that this is the trip
 * they meant. `analyze_trip` is where the agent's loop replaces a progress bar: it returns
 * before anything has been computed, and says so.
 */
#[ResetDatabase]
final class McpDeleteTripTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009c1';

    private Client $client;

    private User $owner;

    private ?string $ownerToken = null;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();
        self::getContainer()->get('cache.mcp_confirmation')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt('owner@example.com');
    }

    #[Test]
    public function deletingAsksFirstAndNamesWhatWouldBeLost(): void
    {
        $this->seedTrip();

        $challenge = $this->structured($this->tool('delete_trip', ['id' => self::TRIP_ID]));

        self::assertTrue($challenge['confirmationRequired'] ?? null);
        self::assertIsString($challenge['confirmationToken'] ?? null);

        $impact = $challenge['impact'] ?? null;
        self::assertIsArray($impact);
        self::assertSame('Traversée du Vercors', $impact['title']);
        self::assertSame(1, $impact['stageCount']);
        self::assertFalse($impact['hasActiveShareLink']);

        // Nothing was written: the trip is still readable.
        self::assertArrayHasKey('version', $this->structured($this->tool('get_trip', ['id' => self::TRIP_ID])));
    }

    #[Test]
    public function theTokenDeletes(): void
    {
        $this->seedTrip();

        $challenge = $this->structured($this->tool('delete_trip', ['id' => self::TRIP_ID]));
        $token = $challenge['confirmationToken'];
        self::assertIsString($token);

        $done = $this->structured($this->tool('delete_trip', ['id' => self::TRIP_ID, 'confirmationToken' => $token]));
        self::assertIsString($done['result'] ?? null);

        // And the trip is gone, which on this transport reads as an error rather than a 404.
        $gone = $this->tool('get_trip', ['id' => self::TRIP_ID])->toArray(false);
        self::assertArrayHasKey('error', $gone);
    }

    /**
     * Nothing is ready when this returns, and saying otherwise would have the agent report
     * finished work that has not started. The answer carries the next move instead: no waiting
     * happens server-side, the agent's own loop is the progress bar (ADR-057 transposed).
     */
    #[Test]
    public function analysingStartsTheWorkAndHandsTheLoopBack(): void
    {
        $this->seedTrip();

        $answer = $this->structured($this->tool('analyze_trip', ['id' => self::TRIP_ID]));

        self::assertIsString($answer['result'] ?? null);
        self::assertIsString($answer['nextAction'] ?? null);
        self::assertStringContainsString('get_trip', $answer['nextAction']);
    }

    /**
     * The lock guard, on the transport where it was absent until the write chains were wired
     * together: re-running fifteen enrichments under someone who has already left is not an
     * edit, it is a surprise.
     */
    #[Test]
    public function aStartedTripRefusesToBeAnalysedAgain(): void
    {
        $this->seedTrip(startDate: new \DateTimeImmutable('-2 days'));

        $refused = $this->tool('analyze_trip', ['id' => self::TRIP_ID])->toArray(false);

        self::assertArrayHasKey('error', $refused);
        self::assertStringContainsString('locked', strtolower(json_encode($refused, \JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function anotherUsersTripIsIndistinguishableFromNoTripAtAll(): void
    {
        $this->seedTrip();

        ['user' => $stranger] = $this->createTestUserWithJwt('stranger@example.com');
        $strangerToken = $this->issueAccessTokenFor($stranger, ['trips:write']);

        $someoneElses = $this->tool('delete_trip', ['id' => self::TRIP_ID], $strangerToken)->toArray(false);
        $nothingAtAll = $this->tool('delete_trip', ['id' => '01936f6e-0000-7000-8000-00000000dead'], $strangerToken)->toArray(false);

        self::assertSame($someoneElses['error'] ?? null, $nothingAtAll['error'] ?? null);
        self::assertArrayHasKey('error', $someoneElses);
        self::assertStringNotContainsString('Vercors', json_encode($someoneElses, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function structured(ResponseInterface $response): array
    {
        $envelope = $response->toArray(false);

        self::assertArrayNotHasKey('error', $envelope, json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        $content = $envelope['result']['structuredContent'] ?? null;
        self::assertIsArray($content);

        return $content;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function tool(string $name, array $arguments, ?string $bearer = null): ResponseInterface
    {
        $bearer ??= $this->ownerToken ??= $this->issueAccessTokenFor($this->owner, ['trips:read', 'trips:write']);

        return $this->client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => $name,
                'Authorization' => 'Bearer '.$bearer,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => $name,
                    'arguments' => $arguments,
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    ],
                ],
            ],
        ]);
    }

    private function seedTrip(?\DateTimeImmutable $startDate = null): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = $startDate;

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
