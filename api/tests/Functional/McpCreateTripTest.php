<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\TripRequestRepositoryInterface;
use App\Tests\ApiTestCase;
use App\Tests\Functional\OAuth\IssuesOAuthTokensTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The first tool that carries a body.
 *
 * Three things are being asserted here that no earlier tool could exercise: that arguments reach
 * the record at all, that the ones the schema does not publish are refused by name rather than
 * dropped, and that the input's validation constraints actually run — the MCP handler defaults
 * `validate` to false, so a creation with no source URL would otherwise leave for the workers
 * and fail three messages later where nobody is listening.
 *
 * And the shape of the answer: nothing is computed when a creation returns, and nothing here
 * pretends otherwise. The agent is told what to call next, because its own loop is the progress
 * bar (ADR-057 transposed).
 */
#[ResetDatabase]
final class McpCreateTripTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    private const string SOURCE_URL = 'https://www.komoot.com/tour/123456789';

    private Client $client;

    private User $owner;

    private ?string $ownerToken = null;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt(\sprintf('creator-%s@example.com', bin2hex(random_bytes(4))));
    }

    #[Test]
    public function creatingAnswersWithAnIdAndWhatToDoNext(): void
    {
        $created = $this->structured($this->tool('create_trip', ['sourceUrl' => self::SOURCE_URL]));

        self::assertIsString($created['id'] ?? null);
        self::assertNotSame('', $created['id']);
        self::assertIsString($created['result'] ?? null);

        // The answer has to say what to do, because there is nothing to report yet. A version is
        // deliberately absent: a trip with no days has nothing to edit.
        self::assertIsString($created['nextAction'] ?? null);
        self::assertStringContainsString('get_trip', $created['nextAction']);
        self::assertStringContainsString($created['id'], $created['nextAction']);
        self::assertArrayNotHasKey('version', $created);

        $trip = $this->storedTrip($created['id']);
        self::assertSame(self::SOURCE_URL, $trip->sourceUrl);
        self::assertSame($this->owner->getId()->toRfc4122(), $trip->user?->getId()?->toRfc4122());
    }

    /** The point of a body: the values sent are the values stored. */
    #[Test]
    public function theSettingsSentAreTheSettingsStored(): void
    {
        $created = $this->structured($this->tool('create_trip', [
            'sourceUrl' => self::SOURCE_URL,
            'title' => 'Traversée du Vercors',
            'startDate' => '2026-07-01T00:00:00+00:00',
            'maxDistancePerDay' => 95.0,
            'ebikeMode' => true,
            'departureHour' => 6,
            'enabledAccommodationTypes' => ['hotel', 'camp_site'],
        ]));

        self::assertIsString($created['id'] ?? null);

        $trip = $this->storedTrip($created['id']);
        self::assertSame('Traversée du Vercors', $trip->title);
        self::assertSame('2026-07-01', $trip->startDate?->format('Y-m-d'));
        self::assertSame(95.0, $trip->maxDistancePerDay);
        self::assertTrue($trip->ebikeMode);
        self::assertSame(6, $trip->departureHour);
        self::assertSame(['hotel', 'camp_site'], $trip->enabledAccommodationTypes);

        // Untouched settings keep their defaults rather than being reset by the merge.
        self::assertSame(0.9, $trip->fatigueFactor);
        self::assertSame(15.0, $trip->averageSpeed);
    }

    /**
     * An argument the schema does not publish is refused, and named.
     *
     * `status` is a real property of the record — it is what says whether a trip's days have
     * been worked out — and it is not in this tool's schema. Accepting it silently would be the
     * worse answer twice over: the trip would claim to be ready, and the model would have no
     * reason to stop sending it.
     */
    #[Test]
    public function anArgumentTheSchemaDoesNotPublishIsRefusedByName(): void
    {
        $envelope = $this->tool('create_trip', [
            'sourceUrl' => self::SOURCE_URL,
            'status' => 'ready',
        ])->toArray(false);

        $message = $envelope['error']['message'] ?? null;
        self::assertIsString($message, json_encode($envelope, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('status', $message);
    }

    /**
     * `validate: true`, doing its job.
     *
     * Without it the transport's default applies, `Assert\NotBlank` in the `trip_request:create`
     * group never runs, and a trip is created with no route to fetch.
     */
    #[Test]
    public function aCreationWithNoSourceUrlIsRefusedBeforeAnythingIsWritten(): void
    {
        $envelope = $this->tool('create_trip', ['title' => 'Nowhere'])->toArray(false);

        self::assertArrayHasKey('error', $envelope, json_encode($envelope, \JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->tripCount(), 'A trip was created without a source URL.');
    }

    /**
     * Two identical calls are a retry, not two intentions.
     *
     * No key is asked of the caller: a model minting a nonce fails in both directions — reusing
     * one literal refuses every creation after the first, minting a fresh one per attempt
     * protects nothing. The server derives one from the user, the tool and the arguments over a
     * short window (ADR-077), so the second call answers with the first call's trip.
     */
    #[Test]
    public function anIdenticalCallAnswersWithTheTripTheFirstOneMade(): void
    {
        $first = $this->structured($this->tool('create_trip', ['sourceUrl' => self::SOURCE_URL, 'maxDistancePerDay' => 95.0]));

        // Same arguments, different key order: the digest is canonical, so this is still a retry.
        $second = $this->structured($this->tool('create_trip', ['maxDistancePerDay' => 95.0, 'sourceUrl' => self::SOURCE_URL]));

        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, $this->tripCount());
    }

    /** Different arguments are a different intention, whatever the window. */
    #[Test]
    public function adifferentCallMakesADifferentTrip(): void
    {
        $first = $this->structured($this->tool('create_trip', ['sourceUrl' => self::SOURCE_URL]));
        $second = $this->structured($this->tool('create_trip', ['sourceUrl' => 'https://www.strava.com/routes/987654321']));

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(2, $this->tripCount());
    }

    private function storedTrip(string $tripId): TripRequest
    {
        $this->entityManager()->clear();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $trip = $repo->getRequest($tripId);
        self::assertInstanceOf(TripRequest::class, $trip);

        return $trip;
    }

    private function tripCount(): int
    {
        $this->entityManager()->clear();

        $count = $this->entityManager()->createQuery('SELECT COUNT(t.id) FROM '.TripRequest::class.' t')->getSingleScalarResult();
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
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
    private function tool(string $name, array $arguments): ResponseInterface
    {
        $bearer = $this->ownerToken ??= $this->issueAccessTokenFor($this->owner, ['trips:write']);

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
}
