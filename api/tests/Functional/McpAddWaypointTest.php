<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Message\RecalculateRouteSegment;
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
 * The one write tool that asks for no version, and says so.
 *
 * Its HTTP twin asks for no `If-Match` either, on purpose: rerouting a day does not move the
 * trip's structural version, and it addresses the day by identity, so a caller working from a
 * list that has moved on still reroutes the day it meant to. Folding this into `edit_stages`
 * would have forced `version` to be optional there and made that tool's description a lie.
 *
 * The lock still applies. The two flags answer different questions: one asks whether the caller
 * is up to date, the other whether the trip may be rewritten at all.
 */
#[ResetDatabase]
final class McpAddWaypointTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009e1';

    private Client $client;

    private User $owner;

    private string $ownerToken;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt(\sprintf('waypoint-%s@example.com', bin2hex(random_bytes(4))));
        $this->ownerToken = $this->issueAccessTokenFor($this->owner, ['trips:write']);
    }

    #[Test]
    public function reroutingDispatchesTheWorkAndAsksForNoVersion(): void
    {
        $this->seedTrip();

        $done = $this->structured($this->tool([
            'tripId' => self::TRIP_ID,
            'stageId' => $this->stages()[0]->id,
            'waypointLat' => 45.15,
            'waypointLon' => 6.15,
        ]));

        self::assertIsString($done['result'] ?? null);
        // Nothing to carry: this write does not move the version, and saying so is what keeps an
        // agent from re-reading the trip for a number that has not changed.
        self::assertArrayNotHasKey('version', $done);
        self::assertIsString($done['nextAction'] ?? null);

        $dispatched = array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()::class,
            $this->transport()->getSent(),
        );
        self::assertContains(RecalculateRouteSegment::class, $dispatched);
    }

    /** The precondition is absent by design; the lock is not. */
    #[Test]
    public function aStartedTripRefusesTheReroute(): void
    {
        $this->seedTrip(startDate: new \DateTimeImmutable('today -1 day'));

        $envelope = $this->tool([
            'tripId' => self::TRIP_ID,
            'stageId' => $this->stages()[0]->id,
            'waypointLat' => 45.15,
            'waypointLon' => 6.15,
        ])->toArray(false);

        $message = $envelope['error']['message'] ?? null;
        self::assertIsString($message, json_encode($envelope, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('locked', $message);
    }

    #[Test]
    public function aDayThatDoesNotExistIsReportedMissing(): void
    {
        $this->seedTrip();

        $envelope = $this->tool([
            'tripId' => self::TRIP_ID,
            'stageId' => '01936f6e-0000-7000-8000-00000000ffff',
            'waypointLat' => 45.15,
            'waypointLon' => 6.15,
        ])->toArray(false);

        $message = $envelope['error']['message'] ?? null;
        self::assertIsString($message, json_encode($envelope, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('not found', $message);
    }

    /** @return list<StageDto> */
    private function stages(): array
    {
        $this->entityManager()->clear();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $stages = $repo->getStages(self::TRIP_ID);
        self::assertIsArray($stages);

        return array_values($stages);
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
            geometry: [new Coordinate(45.0, 6.0, 0.0)],
        )]);

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
                'Mcp-Name' => 'add_waypoint',
                'Authorization' => 'Bearer '.$this->ownerToken,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'add_waypoint',
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
