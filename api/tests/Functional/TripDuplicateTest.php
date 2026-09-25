<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Uid\Uuid;
use App\Tests\ApiTestCase;
use ApiPlatform\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\Stage;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Repository\DoctrineTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class TripDuplicateTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000002';

    private function seedTrip(string $tripId, ?\DateTimeImmutable $startDate = null): void
    {
        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = $startDate ?? new \DateTimeImmutable('2026-07-01');
        $request->fatigueFactor = 0.85;
        $request->elevationPenalty = 40.0;
        $request->title = 'Test Trip';

        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);
        $repo->initializeTrip($tripId, $request);

        // A stage, so cloneStage() is exercised during duplication. Written through the
        // repository rather than attached to the TripRequest before it is created: the stage
        // collection has one writer (ADR-066), and initializeTrip() no longer persists whatever
        // object it is handed — it applies the settings, which is all any caller passes it.
        $repo->storeStages($tripId, [new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(45.0, 6.0, 0.0),
            endPoint: new Coordinate(45.5, 6.5, 0.0),
            label: 'Day 1',
        )]);

        $this->associateTripWithUser($tripId, $this->testUser);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = $container->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations($tripId, ComputationName::pipeline());
        foreach (ComputationName::pipeline() as $computation) {
            $tracker->markDone($tripId, $computation);
        }

    }

    #[Test]
    public function duplicateTripReturnsNewTrip(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/duplicate', self::TRIP_ID),
            ['headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'idempotency-key-for-test-013'], $this->authHeader($this->jwtToken))],
        );

        $this->assertResponseStatusCodeSame(201);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json');

        $data = $response->toArray(false);
        $this->assertNotEmpty($data['id']);
        $this->assertNotSame(self::TRIP_ID, $data['id']);
        $this->assertSame('Trip', $data['@type']);
        $this->assertArrayHasKey('computationStatus', $data);
    }

    #[Test]
    public function duplicateNonExistentTripReturns404(): void
    {
        $this->client->request(
            'POST',
            '/trips/00000000-0000-0000-0000-000000000000/duplicate',
            ['headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'idempotency-key-for-test-014'], $this->authHeader($this->jwtToken))],
        );

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function duplicatedTripPreservesSettings(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/duplicate', self::TRIP_ID),
            ['headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'idempotency-key-for-test-015'], $this->authHeader($this->jwtToken))],
        );

        $this->assertResponseStatusCodeSame(201);

        $data = $response->toArray(false);
        $newId = $data['id'];
        $this->assertNotEmpty($newId);

        // Verify the duplicated trip exists in Doctrine (duplicate is persisted via Doctrine, not Redis)
        $container = self::getContainer();
        /** @var DoctrineTripRequestRepository $repo */
        $repo = $container->get(DoctrineTripRequestRepository::class);
        $duplicated = $repo->getRequest($newId);

        $this->assertInstanceOf(TripRequest::class, $duplicated);
        $this->assertSame('https://www.komoot.com/tour/123456789', $duplicated->sourceUrl);
        $this->assertSame(0.85, $duplicated->fatigueFactor);
        $this->assertSame(40.0, $duplicated->elevationPenalty);

        // Verify stages were deep-cloned (exercises cloneStage())
        $this->assertCount(1, $duplicated->stages);
        $clonedStage = $duplicated->stages->first();
        $this->assertInstanceOf(Stage::class, $clonedStage);
        $this->assertSame(1, $clonedStage->getDayNumber());
        $this->assertSame(80.0, $clonedStage->getDistance());
        $this->assertSame('Day 1', $clonedStage->getLabel());
    }

    #[Test]
    public function duplicatedTripHasAllComputationsMarkedDone(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/duplicate', self::TRIP_ID),
            ['headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'idempotency-key-for-test-016'], $this->authHeader($this->jwtToken))],
        );

        $this->assertResponseStatusCodeSame(201);

        $data = $response->toArray(false);
        foreach (ComputationName::pipeline() as $computation) {
            $this->assertArrayHasKey($computation->value, $data['computationStatus']);
            $this->assertSame('done', $data['computationStatus'][$computation->value]);
        }
    }

    /**
     * The duplicate inherits the source's start date, so cloning a trip that has already
     * started produces a locked one. `isLocked` defaulted to `false` and was emitted anyway —
     * not absent, just wrong (ADR-074).
     */
    #[Test]
    public function duplicatingAStartedTripReportsTheDuplicateAsLocked(): void
    {
        $this->seedTrip(self::TRIP_ID, startDate: new \DateTimeImmutable('today -2 days'));

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/duplicate', self::TRIP_ID),
            ['headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'idempotency-key-for-test-017'], $this->authHeader($this->jwtToken))],
        );

        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($response->toArray(false)['isLocked']);
    }

    /**
     * Where the created resource can be read, for a client that does not parse the JSON-LD
     * body (ADR-074).
     */
    #[Test]
    public function theCreatedTripIsPointedAtByLocation(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/duplicate', self::TRIP_ID),
            ['headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'idempotency-key-for-test-018'], $this->authHeader($this->jwtToken))],
        );

        $this->assertResponseStatusCodeSame(201);
        $this->assertResponseHeaderSame('Location', '/trips/'.$response->toArray(false)['id']);
    }
}
