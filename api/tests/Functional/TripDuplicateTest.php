<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Uid\Uuid;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\Stage;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Repository\DoctrineTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
use App\State\IdempotencyCheckerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class TripDuplicateTest extends ApiTestCase
{
    use ResetDatabase;
    use Factories;
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

    private function seedTrip(string $tripId): void
    {
        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = new \DateTimeImmutable('2026-07-01');
        $request->fatigueFactor = 0.85;
        $request->elevationPenalty = 40.0;
        $request->title = 'Test Trip';

        // Add a stage so cloneStage() is exercised during duplication
        $stage = new Stage($request);
        $stage->setPosition(0);
        $stage->setDayNumber(1);
        $stage->setDistance(80.0);
        $stage->setElevation(500.0);
        $stage->setStartLat(45.0);
        $stage->setStartLon(6.0);
        $stage->setEndLat(45.5);
        $stage->setEndLon(6.5);
        $stage->setLabel('Day 1');

        $request->addStage($stage);

        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);
        $repo->initializeTrip($tripId, $request);
        $this->associateTripWithUser($tripId, $this->testUser);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = $container->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations($tripId, ComputationName::pipeline());
        foreach (ComputationName::pipeline() as $computation) {
            $tracker->markDone($tripId, $computation);
        }

        /** @var IdempotencyCheckerInterface $idempotencyChecker */
        $idempotencyChecker = $container->get(IdempotencyCheckerInterface::class);
        $idempotencyChecker->saveHash($tripId, $request);
    }

    #[Test]
    public function duplicateTripReturnsNewTrip(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/duplicate', self::TRIP_ID),
            ['headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken))],
        );

        $this->assertResponseStatusCodeSame(201);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');

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
            ['headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken))],
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
            ['headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken))],
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
            ['headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken))],
        );

        $this->assertResponseStatusCodeSame(201);

        $data = $response->toArray(false);
        foreach (ComputationName::pipeline() as $computation) {
            $this->assertArrayHasKey($computation->value, $data['computationStatus']);
            $this->assertSame('done', $data['computationStatus'][$computation->value]);
        }
    }
}
