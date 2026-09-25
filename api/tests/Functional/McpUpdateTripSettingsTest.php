<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Message\GenerateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\Tests\ApiTestCase;
use App\Tests\Functional\OAuth\IssuesOAuthTokensTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The only tool that edits a record that already exists, and the four guards it has to satisfy.
 *
 * Merging arguments into a loaded row is where this transport stops resembling the HTTP one.
 * `DeserializeProvider` does that job on HTTP from a position in the provider chain that matters:
 * inside validation, outside `ReadProvider`. {@see \App\State\Mcp\McpDeserializeProvider} takes
 * the same slot, and three of the assertions below fail if it moves:
 *
 *  - a started trip is refused even when the call proposes a future start date. `TripLockProcessor`
 *    judges `previous_data`, which `ReadProvider` publishes as a clone of what it read — merge
 *    any deeper and that clone is the record *after* the edit, so the trip unlocks itself;
 *  - a settings change actually dispatches the recomputation. `TripUpdateProcessor` compares
 *    before with after, and a "before" that is really the after means nothing ever changed;
 *  - a value out of range is refused. Validation runs on what the chain returns, so merging
 *    outside it would check the stored values and pass.
 */
#[ResetDatabase]
final class McpUpdateTripSettingsTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009c1';

    private Client $client;

    private User $owner;

    private string $ownerToken;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();
        self::getContainer()->get('cache.mcp_confirmation')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt(\sprintf('settings-%s@example.com', bin2hex(random_bytes(4))));

        // Issued here and not lazily: reading the stored trip back clears the entity manager,
        // which detaches this user, and minting a token afterwards would persist a refresh token
        // pointing at a detached entity.
        $this->ownerToken = $this->issueAccessTokenFor($this->owner, ['trips:write']);
    }

    /** Asked twice means asked twice: the first call is a question, not a quiet rehearsal. */
    #[Test]
    public function theFirstCallDescribesTheCostAndChangesNothing(): void
    {
        $this->seedTrip();

        $challenge = $this->structured($this->tool([
            'id' => self::TRIP_ID,
            'version' => $this->version(),
            'maxDistancePerDay' => 95.0,
        ]));

        self::assertTrue($challenge['confirmationRequired'] ?? null);
        self::assertIsString($challenge['confirmationToken'] ?? null);
        self::assertIsString($challenge['action'] ?? null);
        self::assertStringContainsString('discards', $challenge['action']);

        self::assertSame(80.0, $this->storedTrip()->maxDistancePerDay, 'The call that only asked wrote the setting anyway.');
    }

    #[Test]
    public function theTokenAppliesTheChangeAndAnswersWithTheNewVersion(): void
    {
        $this->seedTrip();
        $version = $this->version();

        $arguments = ['id' => self::TRIP_ID, 'version' => $version, 'maxDistancePerDay' => 95.0];
        $challenge = $this->structured($this->tool($arguments));
        self::assertIsString($challenge['confirmationToken'] ?? null);

        $done = $this->structured($this->tool($arguments + ['confirmationToken' => $challenge['confirmationToken']]));

        self::assertIsString($done['result'] ?? null);
        self::assertIsInt($done['version'] ?? null);
        self::assertGreaterThan($version, $done['version'], 'A regeneration was dispatched but the answer did not carry the version it produced.');

        self::assertSame(95.0, $this->storedTrip()->maxDistancePerDay);
    }

    /** What is not sent is not touched: the point of merging rather than replacing. */
    #[Test]
    public function theSettingsLeftOutKeepTheirValues(): void
    {
        $this->seedTrip();

        $this->apply(['maxDistancePerDay' => 95.0]);

        $trip = $this->storedTrip();
        self::assertSame(95.0, $trip->maxDistancePerDay);
        self::assertSame(0.75, $trip->fatigueFactor, 'An argument nobody sent was reset to its default.');
        self::assertSame('Traversée du Vercors', $trip->title);
        self::assertSame('2099-07-01', $trip->startDate?->format('Y-m-d'));
    }

    /**
     * The edit reaches the work, not just the row.
     *
     * `TripUpdateProcessor` decides what to recompute by comparing the trip before with the trip
     * after. Hand it a "before" that is really the after and it finds nothing changed: the
     * settings are saved and the days are never recut, which is a trip whose stored numbers
     * stop matching its own settings without a single error anywhere.
     */
    #[Test]
    public function changingThePacingDispatchesTheRecomputation(): void
    {
        $this->seedTrip();

        $this->apply(['maxDistancePerDay' => 95.0]);

        $dispatched = array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()::class,
            $this->transport()->getSent(),
        );

        self::assertContains(GenerateStages::class, $dispatched, 'The settings were saved and nothing was recomputed.');
    }

    /**
     * A started trip cannot edit its way out of the lock.
     *
     * The mirror of {@see TripLockedTest::aStartedTripCannotUnlockItselfByMovingItsStartDate} on
     * this transport, and the assertion that pins where the merge happens: the lock reads the
     * stored start date through `previous_data`, so a merge performed any deeper would hand it
     * the date this very call proposes.
     */
    #[Test]
    public function aStartedTripCannotUnlockItselfByMovingItsStartDate(): void
    {
        $this->seedTrip(startDate: new \DateTimeImmutable('today -1 day'));

        $envelope = $this->tool([
            'id' => self::TRIP_ID,
            'version' => $this->version(),
            'startDate' => '2099-01-01T00:00:00+00:00',
        ])->toArray(false);

        $message = $envelope['error']['message'] ?? null;
        self::assertIsString($message, json_encode($envelope, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('locked', $message);
    }

    /** A stale version is refused before a token is ever minted, so nobody confirms the impossible. */
    #[Test]
    public function aStaleVersionIsRefusedWithoutMintingAToken(): void
    {
        $this->seedTrip();

        $envelope = $this->tool([
            'id' => self::TRIP_ID,
            'version' => $this->version() - 1,
            'maxDistancePerDay' => 95.0,
        ])->toArray(false);

        self::assertArrayHasKey('error', $envelope, json_encode($envelope, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('confirmationToken', json_encode($envelope, \JSON_THROW_ON_ERROR));
    }

    /** Validation judges the values being written, not the ones already stored. */
    #[Test]
    public function aSettingOutOfRangeIsRefused(): void
    {
        $this->seedTrip();

        $envelope = $this->tool([
            'id' => self::TRIP_ID,
            'version' => $this->version(),
            'maxDistancePerDay' => 4000.0,
        ])->toArray(false);

        self::assertArrayHasKey('error', $envelope, json_encode($envelope, \JSON_THROW_ON_ERROR));
        self::assertSame(80.0, $this->storedTrip()->maxDistancePerDay);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function apply(array $settings): void
    {
        $arguments = ['id' => self::TRIP_ID, 'version' => $this->version()] + $settings;

        $challenge = $this->structured($this->tool($arguments));
        self::assertIsString($challenge['confirmationToken'] ?? null);

        $this->structured($this->tool($arguments + ['confirmationToken' => $challenge['confirmationToken']]));
    }

    private function version(): int
    {
        $this->entityManager()->clear();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $version = $repo->getVersion(self::TRIP_ID);
        self::assertIsInt($version);

        return $version;
    }

    private function storedTrip(): TripRequest
    {
        $this->entityManager()->clear();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $trip = $repo->getRequest(self::TRIP_ID);
        self::assertInstanceOf(TripRequest::class, $trip);

        return $trip;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function seedTrip(?\DateTimeImmutable $startDate = null): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = $startDate ?? new \DateTimeImmutable('2099-07-01');
        $request->maxDistancePerDay = 80.0;
        $request->fatigueFactor = 0.75;
        $request->title = 'Traversée du Vercors';

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $repo->initializeTrip(self::TRIP_ID, $request);
        $repo->storeStages(self::TRIP_ID, [new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(45.0, 6.0, 0.0),
            endPoint: new Coordinate(45.5, 6.5, 0.0),
        )]);

        // Re-read rather than reuse: the kernel is reset between setUp and the test body, so the
        // instance created there is detached by now and associating it would make Doctrine treat
        // the owner as a brand new user.
        $owner = $this->entityManager()->find(User::class, $this->owner->getId());
        self::assertInstanceOf(User::class, $owner);

        $this->associateTripWithUser(self::TRIP_ID, $owner);
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
    private function tool(array $arguments): ResponseInterface
    {
        return $this->client->request('POST', '/mcp', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => 'update_trip_settings',
                'Authorization' => 'Bearer '.$this->ownerToken,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'update_trip_settings',
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
