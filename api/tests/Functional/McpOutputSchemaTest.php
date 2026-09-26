<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use ApiPlatform\Test\Constraint\MatchesJsonSchema;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Enum\AlertGroup;
use App\Repository\DoctrineTripRequestRepository;
use App\Tests\ApiTestCase;
use App\Tests\Functional\OAuth\IssuesOAuthTokensTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * What `tools/list` promises about an answer is what the answer is.
 *
 * The `outputSchema` of a tool is built from the class its operation declares as output, and
 * falls back to the resource class when none is declared. None was, so every tool published
 * the schema of a class it never answers with: `get_stage` announced `geometry`,
 * `alertsByGroup` and `supplyTimeline` — precisely what its projection drops — and `get_trip`
 * announced arrays that the answer delivered as Hydra objects, which a client validating the
 * answer against the schema would have refused.
 *
 * The schema is also prose a model reads: a property it announces, the model expects. So the
 * check is on the published document itself, fetched the way a client fetches it, against the
 * answers those same tools give.
 */
#[ResetDatabase]
final class McpOutputSchemaTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009f1';

    private Client $client;

    private User $owner;

    private ?string $token = null;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();
        self::getContainer()->get('cache.mcp_confirmation')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt('owner@example.com');
    }

    #[Test]
    public function everyAnswerMatchesTheSchemaItsToolPublishes(): void
    {
        $stageId = $this->seedTrip();
        $schemas = $this->publishedSchemas();

        $answers = [
            'get_trip' => $this->structured('get_trip', ['id' => self::TRIP_ID]),
            'get_stage' => $this->structured('get_stage', ['tripId' => self::TRIP_ID, 'stageId' => $stageId]),
            'list_trips' => $this->structured('list_trips', []),
            'share_trip' => $this->structured('share_trip', ['tripId' => self::TRIP_ID]),
        ];

        // Both halves of the two-call shape, against the one schema that has to describe both.
        $challenge = $this->structured('delete_trip', ['id' => self::TRIP_ID]);
        $answers['delete_trip (challenge)'] = $challenge;
        $answers['delete_trip (confirmed)'] = $this->structured('delete_trip', ['id' => self::TRIP_ID, 'confirmationToken' => $challenge['confirmationToken']]);

        // Last, because it needs a kernel of its own: the container refuses to replace a service
        // it has already built, and every call above has built the HTTP client this one mocks.
        $answers['search_places'] = $this->searchPlaces();

        foreach ($answers as $label => $answer) {
            $tool = explode(' ', $label)[0];
            self::assertArrayHasKey($tool, $schemas, $tool.' publishes no output schema at all.');
            self::assertThat($answer, new MatchesJsonSchema($schemas[$tool]), $label.' does not match the schema its tool publishes.');
        }
    }

    /**
     * The two tools that answer a page rather than a record publish an envelope.
     *
     * Kept separate from the loop above because a schema can describe an answer and still be
     * the wrong schema: `list_trips` published the shape of a single `TripListItem`, which
     * matched its collection answer for the only reason that it required nothing and forbade
     * nothing. What is asserted here is the shape itself — `member` carrying the records, and
     * `required` naming it, so a client has something to check.
     */
    #[Test]
    public function aToolThatAnswersAListPublishesAnEnvelope(): void
    {
        $schemas = $this->publishedSchemas();

        foreach (['list_trips', 'search_places'] as $tool) {
            self::assertArrayHasKey($tool, $schemas, $tool.' publishes no output schema at all.');

            $properties = $schemas[$tool]['properties'] ?? [];
            self::assertIsArray($properties);
            self::assertArrayHasKey('member', $properties, $tool.' publishes no `member`: it announces one record as the whole answer.');
            self::assertArrayHasKey('totalItems', $properties, $tool.' publishes no `totalItems`.');
            self::assertSame(['member'], $schemas[$tool]['required'] ?? null, $tool.' does not require `member`, so its schema forbids nothing.');

            // And the records are described, rather than the envelope being an empty promise.
            $items = $properties['member']['items'] ?? [];
            self::assertIsArray($items);
            self::assertNotSame([], $items['properties'] ?? [], $tool.' describes no property of the records it returns.');
        }
    }

    /**
     * The regression by name: the drill-down drops the geometry on purpose, and its schema must
     * not announce it.
     */
    #[Test]
    public function aDroppedFieldIsNotAnnounced(): void
    {
        $properties = $this->publishedSchemas()['get_stage']['properties'] ?? null;
        self::assertIsArray($properties);

        foreach (['geometry', 'alertsByGroup', 'supplyTimeline', 'tripId'] as $dropped) {
            self::assertArrayNotHasKey($dropped, $properties, 'get_stage announces `'.$dropped.'`, which it never answers with.');
        }
    }

    /**
     * `search_places`, with Nominatim mocked — the answer has to be this project's, not a live
     * one, and the mock only installs on a kernel whose HTTP client has not been built yet.
     *
     * @return array<array-key, mixed>
     */
    private function searchPlaces(): array
    {
        $this->token ??= $this->issueAccessTokenFor($this->owner, ['trips:read', 'trips:write']);

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        self::getContainer()->set('nominatim.client', new MockHttpClient(
            static fn (): MockResponse => new MockResponse(json_encode([[
                'name' => 'Grenoble',
                'display_name' => 'Grenoble, Isère, France',
                'lat' => '45.1885',
                'lon' => '5.7245',
                'addresstype' => 'city',
            ]], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]),
        ));

        return $this->structured('search_places', ['q' => 'Grenoble']);
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    private function publishedSchemas(): array
    {
        $envelope = $this->rpc('tools/list', [], null)->toArray(false);

        $schemas = [];
        foreach ($envelope['result']['tools'] ?? [] as $tool) {
            self::assertIsArray($tool);
            self::assertIsString($tool['name'] ?? null);
            if (\is_array($tool['outputSchema'] ?? null)) {
                $schemas[$tool['name']] = $tool['outputSchema'];
            }
        }

        return $schemas;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function structured(string $tool, array $arguments): array
    {
        $envelope = $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments], $tool)->toArray(false);

        self::assertArrayNotHasKey('error', $envelope, json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        $content = $envelope['result']['structuredContent'] ?? null;
        self::assertIsArray($content);

        return $content;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function rpc(string $method, array $params, ?string $name): ResponseInterface
    {
        // Issued on first use rather than in setUp(): the token exchange clears the entity
        // manager, and a trip seeded afterwards would find its owner detached.
        $this->token ??= $this->issueAccessTokenFor($this->owner, ['trips:read', 'trips:write']);

        return $this->client->request('POST', '/mcp', [
            'headers' => array_filter([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => $method,
                'Mcp-Name' => $name,
                'Authorization' => 'Bearer '.$this->token,
            ]),
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params + [
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    ],
                ],
            ],
        ]);
    }

    private function seedTrip(): string
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

        $stageId = ($repo->getStages(self::TRIP_ID) ?? [])[0]->id ?? null;
        self::assertIsString($stageId);

        // A real alert payload, not an empty list. The first version of this test validated
        // get_stage against a stage with no alert, so a schema that declared every alert value a
        // string passed here — and a validating client (the MCP Inspector) refused every real
        // answer: producers publish numbers (`lat`, `lon`) and objects (`parameters`, `action`).
        $repo->updateStageAlertsForGroup(self::TRIP_ID, $stageId, AlertGroup::POIS, [[
            'code' => 'cultural_poi_suggestion',
            'type' => 'nudge',
            'messageKey' => 'alert.cultural_poi.suggestion',
            'parameters' => ['%name%' => 'Musée du vélo', '%type%' => 'museum', '%distance%' => 120],
            'parameterFormats' => ['%distance%' => 'distance'],
            'lat' => 45.1,
            'lon' => 6.1,
            'poiName' => 'Musée du vélo',
            'action' => ['kind' => 'navigate', 'labelKey' => 'alert.ferry.action', 'payload' => ['lat' => 45.1, 'lon' => 6.1]],
        ]]);

        return $stageId;
    }
}
