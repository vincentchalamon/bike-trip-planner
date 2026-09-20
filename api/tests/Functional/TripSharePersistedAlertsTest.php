<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Enum\AlertGroup;
use App\Repository\DoctrineTripRequestRepository;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The reason lot B exists: an anonymous visitor gets no SSE, so whatever is not persisted is
 * simply not there.
 *
 * Before ADR-068 only `terrain` was written, and `/s/{shortCode}` served a roadbook missing
 * twelve families of alerts. This walks the whole public path — seed, share, fetch anonymously
 * — and asserts every group comes back, each carrying the tag the client needs to keep them
 * apart.
 *
 * Seeded through the Doctrine repository rather than the aliased interface, because that is
 * what the share provider reads (via {@see \App\State\TripDetailProvider}); the test-env alias
 * points the interface at the transient implementation.
 */
#[ResetDatabase]
final class TripSharePersistedAlertsTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000902';

    private Client $client;

    private User $owner;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->owner, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('share-alerts@example.com');
    }

    #[Test]
    public function anAnonymousVisitorSeesEveryEnrichmentGroup(): void
    {
        $repository = $this->doctrineRepository();
        $this->seedTrip($repository);

        $stageId = ($repository->getStages(self::TRIP_ID) ?? [])[0]->id;
        foreach (AlertGroup::cases() as $group) {
            $repository->updateStageAlertsForGroup(self::TRIP_ID, $stageId, $group, [[
                'code' => null,
                'type' => 'nudge',
                'message' => \sprintf('Alert from %s', $group->value),
            ]]);
        }

        $shortCode = $this->createShare();

        // No Authorization header: this is the whole point of the endpoint.
        $response = $this->client->request('GET', \sprintf('/s/%s', $shortCode), [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $alerts = $response->toArray(false)['stages'][0]['alerts'];

        $groups = array_column($alerts, 'group');
        sort($groups);
        $expected = AlertGroup::VALUES;
        sort($expected);

        self::assertSame($expected, $groups, 'Every producer must survive to the public page.');
    }

    /**
     * The one thing the groups exist for: a producer replaces its own alerts and no others.
     */
    #[Test]
    public function reRunningOneProducerLeavesTheOtherGroupsOnThePublicPage(): void
    {
        $repository = $this->doctrineRepository();
        $this->seedTrip($repository);

        $stageId = ($repository->getStages(self::TRIP_ID) ?? [])[0]->id;
        $repository->updateStageAlertsForGroup(self::TRIP_ID, $stageId, AlertGroup::FERRY, [
            ['code' => 'ferry_crossing', 'type' => 'warning', 'message' => 'Ferry'],
        ]);
        $repository->updateStageAlertsForGroup(self::TRIP_ID, $stageId, AlertGroup::TERRAIN, [
            ['code' => 'elevation_gain', 'type' => 'warning', 'message' => 'Climb'],
        ]);
        // Terrain runs again and finds nothing; the ferry must not go with it.
        $repository->updateStageAlertsForGroup(self::TRIP_ID, $stageId, AlertGroup::TERRAIN, []);

        $shortCode = $this->createShare();
        $response = $this->client->request('GET', \sprintf('/s/%s', $shortCode), [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $alerts = $response->toArray(false)['stages'][0]['alerts'];

        self::assertSame(['ferry'], array_column($alerts, 'group'));
        self::assertSame('Ferry', $alerts[0]['message']);
    }

    private function createShare(): string
    {
        $response = $this->client->request('POST', \sprintf('/trips/%s/share', self::TRIP_ID), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [],
        ]);
        $this->assertResponseStatusCodeSame(201);

        return (string) $response->toArray(false)['shortCode'];
    }

    private function doctrineRepository(): DoctrineTripRequestRepository
    {
        $repository = self::getContainer()->get(DoctrineTripRequestRepository::class);
        \assert($repository instanceof DoctrineTripRequestRepository);

        return $repository;
    }

    private function seedTrip(DoctrineTripRequestRepository $repository): void
    {
        $request = new TripRequest(Uuid::fromString(self::TRIP_ID));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        $repository->initializeTrip(self::TRIP_ID, $request);
        $repository->storeTitle(self::TRIP_ID, 'Shared trip with every group');
        $repository->storeStatus(self::TRIP_ID, 'ready');
        $this->associateTripWithUser(self::TRIP_ID, $this->owner);

        $repository->storeStages(self::TRIP_ID, [new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1200.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.5, 6.5, 800.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0), new Coordinate(45.5, 6.5, 800.0)],
        )]);
    }
}
