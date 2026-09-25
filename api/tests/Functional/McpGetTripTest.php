<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Repository\DoctrineTripRequestRepository;
use App\Tests\ApiTestCase;
use App\Tests\Functional\OAuth\IssuesOAuthTokensTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * `get_trip` as an agent receives it: a digest, and the version an edit will have to pin.
 *
 * The version is the load-bearing assertion. On HTTP it travels as the `ETag` and comes back
 * as `If-Match`; a `tools/call` answer has no per-call headers, and `TripRequest::$version` is
 * `ApiProperty(readable: false)`, so without it in this body an agent has nothing to send and
 * every write tool is unreachable. It is not a nicety, it is what makes the rest of unit 3B
 * callable at all.
 */
#[ResetDatabase]
final class McpGetTripTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009a1';

    private Client $client;

    private User $owner;

    private ?string $ownerToken = null;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt('owner@example.com');
    }

    /** Without this, nothing in PR2 or PR3 can be called. */
    #[Test]
    public function theAnswerCarriesTheVersionAnEditMustPin(): void
    {
        $this->seedTrip();

        $digest = $this->getTrip();

        self::assertArrayHasKey('version', $digest, 'No version: an agent has nothing to send as `version` and every write tool is unreachable.');
        self::assertIsInt($digest['version']);
        self::assertGreaterThan(0, $digest['version']);
    }

    #[Test]
    public function oneLinePerDayWithWhatDecidesWhetherToLookCloser(): void
    {
        $this->seedTrip();

        $digest = $this->getTrip();

        self::assertSame(1, $digest['stageCount']);
        self::assertSame(85.5, $digest['totalDistance']);

        $stage = $this->firstStage($digest);
        self::assertSame(1, $stage['dayNumber']);
        self::assertSame(85.5, $stage['distance']);
        self::assertSame('Grenoble', $stage['startLabel']);
        self::assertSame('Villard-de-Lans', $stage['endLabel']);
        self::assertSame('Gîte du Vercors', $stage['accommodation']);
        self::assertFalse($stage['isRestDay']);
        self::assertArrayHasKey('alertCount', $stage);
    }

    /**
     * Measured, and the reason `categoryStatus` is a list of pairs rather than a map.
     *
     * The JSON-LD normalizer renders every array property as a Hydra `Collection` carrying its
     * **values only**: `{"route": "done", "weather": "running"}` leaves as
     * `{"member": ["done", "running"]}` and the keys are gone, so the reader is told two
     * statuses and cannot tell what either describes. Nothing in a tool's answer may be keyed
     * by data.
     */
    #[Test]
    public function aStatusStaysAttachedToTheFamilyItDescribes(): void
    {
        $this->seedTrip();
        $this->seedComputationStatuses();

        $members = $this->getTrip()['categoryStatus'] ?? null;
        self::assertIsArray($members);
        self::assertTrue(array_is_list($members), 'A list, as the published schema says — not a Hydra envelope.');
        self::assertNotSame([], $members, 'Nothing to prove against — seed a status first.');

        foreach ($members as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('category', $entry, 'A bare status: the family it describes was lost in the envelope.');
            self::assertArrayHasKey('status', $entry);
            self::assertIsString($entry['category']);
        }
    }

    /**
     * The heavy blocks are what make an enriched fortnight unreadable by a model, and none of
     * them helps it decide anything. Dropping them is the point of the digest.
     */
    #[Test]
    public function theHeavyBlocksAreNotThere(): void
    {
        $this->seedTrip();

        $stage = $this->firstStage($this->getTrip());

        foreach (['geometry', 'events', 'supplyTimeline', 'resupply', 'accommodations', 'weather'] as $dropped) {
            self::assertArrayNotHasKey($dropped, $stage, \sprintf('`%s` is back in the digest.', $dropped));
        }
    }

    /**
     * A trip whose days are still being computed must say so. Without the flag a model reports
     * that a trip created seconds ago has no stages — false, and the user cannot correct it,
     * they never saw the payload.
     */
    #[Test]
    public function aTripStillBeingComputedSaysItIsPartial(): void
    {
        $this->seedTrip(ready: false);

        $digest = $this->getTrip();

        self::assertTrue($digest['partial']);
        self::assertSame('draft', $digest['status']);
    }

    #[Test]
    public function aComputedTripIsNotPartial(): void
    {
        $this->seedTrip();

        self::assertFalse($this->getTrip()['partial']);
    }

    /**
     * Third-party text cannot change the shape of the answer around it. Newlines in an OSM
     * place name would forge what looks like the end of one field and the start of another;
     * an unbounded name is a denial of service against the budget the digest exists to keep.
     *
     * This is structural hygiene, NOT a prompt-injection defence — that posture is unit 3C.
     */
    #[Test]
    public function thirdPartyTextCannotReshapeTheAnswer(): void
    {
        $this->seedTrip(startLabel: "Gre\nnoble\u{0007}", endLabel: str_repeat('a', 250));

        $stage = $this->firstStage($this->getTrip());

        self::assertSame('Gre noble', $stage['startLabel']);
        self::assertStringNotContainsString("\n", (string) $stage['startLabel']);
        self::assertIsString($stage['endLabel']);
        self::assertLessThanOrEqual(201, mb_strlen($stage['endLabel']));
    }

    /**
     * An array property arrives as a plain list. It used to arrive wrapped in a Hydra
     * `Collection` — an object — while the published `outputSchema` said `array`, so a client
     * validating the answer against the schema would have refused it. Declaring the tool's
     * `output:` class is what aligns the two: the schema is built from the class the answer is
     * actually made of, and the arrays in it are normalised as the lists they are.
     *
     * @param array<array-key, mixed> $digest
     *
     * @return array<array-key, mixed>
     */
    private function firstStage(array $digest): array
    {
        $stages = $digest['stages'] ?? null;
        self::assertIsArray($stages);
        self::assertTrue(array_is_list($stages));

        $first = $stages[0] ?? null;
        self::assertIsArray($first);

        return $first;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function getTrip(): array
    {
        $this->ownerToken ??= $this->issueAccessTokenFor($this->owner);

        $envelope = $this->call(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_trip',
                    'arguments' => ['id' => self::TRIP_ID],
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    ],
                ],
            ],
        )->toArray(false);

        self::assertArrayNotHasKey('error', $envelope, json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        $digest = $envelope['result']['structuredContent'] ?? null;
        self::assertIsArray($digest);

        return $digest;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function call(array $body): ResponseInterface
    {
        return $this->client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => 'get_trip',
                'Authorization' => 'Bearer '.$this->ownerToken,
            ],
            'json' => $body,
        ]);
    }

    private function seedComputationStatuses(): void
    {
        /** @var ComputationTrackerInterface $tracker */
        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);

        $tracker->initializeComputations(self::TRIP_ID, [ComputationName::ROUTE, ComputationName::WEATHER]);
        $tracker->markDone(self::TRIP_ID, ComputationName::ROUTE);
        $tracker->markRunning(self::TRIP_ID, ComputationName::WEATHER);
    }

    private function seedTrip(bool $ready = true, string $startLabel = 'Grenoble', string $endLabel = 'Villard-de-Lans'): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        /** @var DoctrineTripRequestRepository $repo */
        $repo = self::getContainer()->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip(self::TRIP_ID, $request);
        $repo->storeTitle(self::TRIP_ID, 'Traversée du Vercors');
        $this->associateTripWithUser(self::TRIP_ID, $this->owner);

        $stage = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1200.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.5, 6.5, 800.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0)],
        );
        $stage->startLabel = $startLabel;
        $stage->endLabel = $endLabel;
        $stage->selectedAccommodation = new Accommodation(
            name: 'Gîte du Vercors',
            type: 'hostel',
            lat: 45.5,
            lon: 6.5,
            estimatedPriceMin: 25.0,
            estimatedPriceMax: 40.0,
            isExactPrice: false,
        );

        $repo->storeStages(self::TRIP_ID, [$stage]);

        if ($ready) {
            $repo->storeStatus(self::TRIP_ID, 'ready');
        }
    }
}
