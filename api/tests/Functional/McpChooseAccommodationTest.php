<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
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
 * Choosing where to sleep, which moves the day it belongs to.
 *
 * The place is addressed by its coordinates because it has no stable identifier: an
 * OpenStreetMap entry is keyed by an (osmType, osmId) pair a DataTourisme one does not have, and
 * a manually-added one has neither. The coordinates are what `get_stage` publishes for every
 * option, and what the HTTP operation takes — one addressing scheme, not two.
 */
#[ResetDatabase]
final class McpChooseAccommodationTest extends ApiTestCase
{
    use IssuesOAuthTokensTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string PROTOCOL_VERSION = '2026-07-28';

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009f1';

    private const float LAT = 45.4;

    private const float LON = 6.4;

    private Client $client;

    private User $owner;

    private string $ownerToken;

    #[\Override]
    protected function setUp(): void
    {
        self::getContainer()->get('cache.oauth_consent')->clear();

        $this->client = self::createClient();
        ['user' => $this->owner] = $this->createTestUserWithJwt(\sprintf('sleep-%s@example.com', bin2hex(random_bytes(4))));
        $this->ownerToken = $this->issueAccessTokenFor($this->owner, ['trips:write']);
    }

    #[Test]
    public function choosingAPlaceMovesTheEndOfTheDayAndAnswersWithTheNewVersion(): void
    {
        $this->seedTrip();
        $version = $this->version();

        $done = $this->structured($this->tool([
            'tripId' => self::TRIP_ID,
            'stageId' => $this->stages()[0]->id,
            'version' => $version,
            'selectedAccommodationLat' => self::LAT,
            'selectedAccommodationLon' => self::LON,
        ]));

        self::assertIsString($done['result'] ?? null);
        self::assertIsInt($done['version'] ?? null);
        self::assertGreaterThan($version, $done['version']);

        $stage = $this->stages()[0];
        self::assertNotNull($stage->selectedAccommodation);
        self::assertSame('Refuge du Col', $stage->selectedAccommodation->name);
        self::assertSame(self::LAT, $stage->endPoint->lat);
    }

    #[Test]
    public function leavingTheCoordinatesOutClearsTheChoice(): void
    {
        $this->seedTrip();

        $this->structured($this->tool([
            'tripId' => self::TRIP_ID,
            'stageId' => $this->stages()[0]->id,
            'version' => $this->version(),
            'selectedAccommodationLat' => self::LAT,
            'selectedAccommodationLon' => self::LON,
        ]));

        $this->structured($this->tool([
            'tripId' => self::TRIP_ID,
            'stageId' => $this->stages()[0]->id,
            'version' => $this->version(),
        ]));

        self::assertNull($this->stages()[0]->selectedAccommodation);
    }

    #[Test]
    public function aStaleVersionIsRefused(): void
    {
        $this->seedTrip();

        $envelope = $this->tool([
            'tripId' => self::TRIP_ID,
            'stageId' => $this->stages()[0]->id,
            'version' => $this->version() - 1,
            'selectedAccommodationLat' => self::LAT,
            'selectedAccommodationLon' => self::LON,
        ])->toArray(false);

        self::assertArrayHasKey('error', $envelope, json_encode($envelope, \JSON_THROW_ON_ERROR));
        self::assertNull($this->stages()[0]->selectedAccommodation);
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

    private function version(): int
    {
        $this->entityManager()->clear();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $version = $repo->getVersion(self::TRIP_ID);
        self::assertIsInt($version);

        return $version;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function seedTrip(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = new \DateTimeImmutable('2099-07-01');

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $repo->initializeTrip(self::TRIP_ID, $request);

        $first = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(45.0, 6.0, 0.0),
            endPoint: new Coordinate(45.5, 6.5, 0.0),
            geometry: [new Coordinate(45.0, 6.0, 0.0)],
        );
        $first->addAccommodation(new Accommodation(
            name: 'Refuge du Col',
            type: 'alpine_hut',
            lat: self::LAT,
            lon: self::LON,
            estimatedPriceMin: 20.0,
            estimatedPriceMax: 40.0,
            isExactPrice: false,
        ));

        $second = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 2,
            distance: 70.0,
            elevation: 400.0,
            startPoint: new Coordinate(45.5, 6.5, 0.0),
            endPoint: new Coordinate(46.0, 7.0, 0.0),
            geometry: [new Coordinate(45.5, 6.5, 0.0)],
        );

        $repo->storeStages(self::TRIP_ID, [$first, $second]);

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
                'Mcp-Name' => 'choose_accommodation',
                'Authorization' => 'Bearer '.$this->ownerToken,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'choose_accommodation',
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
