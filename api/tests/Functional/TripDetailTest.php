<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Repository\DoctrineTripRequestRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TripDetailTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000301';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    private function seedTrip(string $tripId): DoctrineTripRequestRepository
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        /** @var DoctrineTripRequestRepository $repo */
        $repo = self::getContainer()->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip($tripId, $request);
        $repo->storeTitle($tripId, 'Detail test trip');
        $this->associateTripWithUser($tripId, $this->testUser);

        return $repo;
    }

    #[Test]
    public function detailReturnsExpectedFields(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);

        $stage = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1200.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.5, 6.5, 800.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0)],
        );
        $repo->storeStages(self::TRIP_ID, [$stage]);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $this->assertSame(self::TRIP_ID, $data['id']);
        $this->assertArrayHasKey('stages', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertSame('ready', $data['status']);
        $this->assertArrayHasKey('fatigueFactor', $data);
        $this->assertArrayHasKey('enabledAccommodationTypes', $data);
        // Coverage polygon is unprovisioned in the test DB, so a valid trip is in zone.
        $this->assertArrayHasKey('outOfZone', $data);
        $this->assertFalse($data['outOfZone']);
        $this->assertNotEmpty($data['stages']);
        $stage = $data['stages'][0];
        $this->assertArrayHasKey('dayNumber', $stage);
        $this->assertArrayHasKey('distance', $stage);
        $this->assertArrayHasKey('geometry', $stage);
        $this->assertArrayHasKey('alerts', $stage);
        $this->assertArrayHasKey('accommodations', $stage);
        // No cycle routes provisioned in the test DB → stage is not on a network.
        $this->assertArrayHasKey('onCycleNetwork', $stage);
        $this->assertEqualsWithDelta(0.0, $stage['onCycleNetwork'], 0.0001);
        // No computations tracked for this trip → block statuses are null. JSON-LD
        // strips null properties, so they are absent here (the front reads them as
        // undefined and falls back to the presence of the underlying data).
        $this->assertNull($data['weatherStatus'] ?? null);
        $this->assertNull($data['aiStatus'] ?? null);
    }

    #[Test]
    public function detailExposesRunningBlockStatusWhileComputing(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::WEATHER,
            ComputationName::WIND,
            ComputationName::STAGE_AI_ANALYSIS,
            ComputationName::TRIP_AI_OVERVIEW,
        ]);
        // Weather: one done, one still running → running. AI: both pending → running.
        $tracker->markDone(self::TRIP_ID, ComputationName::WEATHER);
        $tracker->markRunning(self::TRIP_ID, ComputationName::WIND);

        $data = $this->fetchDetail();
        $this->assertSame('running', $data['weatherStatus']);
        $this->assertSame('running', $data['aiStatus']);
    }

    #[Test]
    public function detailExposesDoneBlockStatusWhenAllComputationsSucceed(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::WEATHER,
            ComputationName::WIND,
            ComputationName::STAGE_AI_ANALYSIS,
            ComputationName::TRIP_AI_OVERVIEW,
        ]);
        $tracker->markDone(self::TRIP_ID, ComputationName::WEATHER);
        $tracker->markDone(self::TRIP_ID, ComputationName::WIND);
        $tracker->markDone(self::TRIP_ID, ComputationName::STAGE_AI_ANALYSIS);
        $tracker->markDone(self::TRIP_ID, ComputationName::TRIP_AI_OVERVIEW);

        $data = $this->fetchDetail();
        $this->assertSame('done', $data['weatherStatus']);
        $this->assertSame('done', $data['aiStatus']);
    }

    #[Test]
    public function detailExposesFailedBlockStatusWhenAllTerminalAndNoneSucceeded(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::WEATHER,
            ComputationName::WIND,
        ]);
        // All weather computations terminal with at least one failed and zero done → failed.
        $tracker->markFailed(self::TRIP_ID, ComputationName::WEATHER);
        $tracker->markFailed(self::TRIP_ID, ComputationName::WIND);

        $data = $this->fetchDetail();
        $this->assertSame('failed', $data['weatherStatus']);
        // AI computations were never tracked → null, stripped from the JSON-LD payload.
        $this->assertNull($data['aiStatus'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDetail(): array
    {
        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        return $response->toArray(false);
    }

    #[Test]
    public function detailExposesDraftStatusForTripWithoutStages(): void
    {
        // A freshly initialized trip with no stages keeps the default `draft` status.
        $this->seedTrip(self::TRIP_ID);

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSame('draft', $response->toArray(false)['status']);
    }

    #[Test]
    public function detailFallsBackToReadyForLegacyTripWithStagesAndEmptyStatus(): void
    {
        // Simulates a trip persisted before the status column existed: status is blank
        // in DB, so the provider infers readiness from the presence of stages.
        $repo = $this->seedTrip(self::TRIP_ID);

        $stage = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 40.0,
            elevation: 300.0,
            startPoint: new Coordinate(48.5, 3.0, 0.0),
            endPoint: new Coordinate(48.6, 3.1, 0.0),
        );
        $repo->storeStages(self::TRIP_ID, [$stage]);

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $connection->executeStatement("UPDATE trip SET status = '' WHERE id = :id", ['id' => self::TRIP_ID]);

        // Drop the Doctrine identity map so the provider re-reads the blanked status
        // from the DB instead of returning the still-managed entity (status='draft').
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $em->clear();

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSame('ready', $response->toArray(false)['status']);
    }

    #[Test]
    public function detailFlagsOutOfZoneFromStageEndpointsWhenGeometryIsEmpty(): void
    {
        // Exercises the routePoints() fallback (no stage geometry → start/end points)
        // against the real ST_Covers query: endpoints sit at lon 10, outside the
        // seeded coverage polygon (2..4 lon, 48..50 lat), so the trip is out of zone.
        $repo = $this->seedTrip(self::TRIP_ID);

        $stage = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 40.0,
            elevation: 300.0,
            startPoint: new Coordinate(48.5, 10.0, 0.0),
            endPoint: new Coordinate(48.6, 10.1, 0.0),
        );
        $repo->storeStages(self::TRIP_ID, [$stage]);

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $connection->executeStatement('TRUNCATE osm.coverage');
        $connection->executeStatement(<<<'SQL'
            INSERT INTO osm.coverage (geom) VALUES (
                ST_Multi(ST_SetSRID(ST_GeomFromText('POLYGON((2 48, 4 48, 4 50, 2 50, 2 48))'), 4326))
            )
            SQL);

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($response->toArray(false)['outOfZone']);
    }

    #[Test]
    public function detailNonExistentTripReturns404(): void
    {
        // Object-level authz denials are surfaced as 404 (ADR-038): an unknown trip
        // and a foreign trip are indistinguishable, so existence is not leaked.
        $this->client->request('GET', '/trips/00000000-0000-0000-0000-000000000000/detail', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function detailOfAnotherUsersTripReturns404(): void
    {
        // IDOR-DETAIL regression: a trip owned by someone else must not be readable,
        // and is hidden as 404 (not 403) so its existence is not revealed (ADR-038).
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStages(self::TRIP_ID, []);

        ['token' => $otherToken] = $this->createTestUserWithJwt('intruder@example.com');

        $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($otherToken)),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function unauthenticatedRequestReturns401(): void
    {
        $this->client->request('GET', '/trips/01936f6e-0000-7000-8000-000000000001/detail');

        $this->assertResponseStatusCodeSame(401);
    }
}
