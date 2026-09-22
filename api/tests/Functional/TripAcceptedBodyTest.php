<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Repository\TripRequestRepositoryInterface;
use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * What a 202 says about the work it accepted (ADR-074).
 *
 * `Trip` used to declare `computationStatus = []` and `isLocked = false` as defaults. Neither
 * property is in a serialization group, so a default is never *absent* from the body — it is
 * emitted. `/recompute` answered `new Trip(id: $tripId)` on both of its paths, so it claimed
 * nothing was being computed in the same breath as dispatching the work, and `/analyze` said a
 * trip was unlocked without ever asking.
 *
 * `POST /trips/{id}/analyze` had no functional test at all, and no test at any level asserted
 * the body `/recompute` returns.
 */
#[ResetDatabase]
final class TripAcceptedBodyTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000901';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    private function seedTrip(?\DateTimeImmutable $startDate = null): void
    {
        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString(self::TRIP_ID));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = $startDate;

        $repo->initializeTrip(self::TRIP_ID, $request);
        $this->associateTripWithUser(self::TRIP_ID, $this->testUser);
        $repo->storeStages(self::TRIP_ID, [new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1200.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.5, 6.5, 800.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0)],
        )]);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = $container->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations(self::TRIP_ID, ComputationName::pipeline());
    }

    #[Test]
    public function recomputeReportsTheWorkItJustDispatched(): void
    {
        $this->seedTrip();

        $response = $this->client->request('POST', \sprintf('/trips/%s/recompute', self::TRIP_ID), [
            'headers' => array_merge([
                'Content-Type' => 'application/ld+json',
                'If-Match' => '*',
            ], $this->authHeader($this->jwtToken)),
            'json' => ['modifications' => [['type' => 'pacing']]],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $data = $response->toArray(false);
        $this->assertSame(self::TRIP_ID, $data['id']);
        // It answered `[]` here, while the messages were already on the bus.
        $this->assertNotSame([], $data['computationStatus']);
        $this->assertArrayHasKey('route', $data['computationStatus']);
        $this->assertFalse($data['isLocked']);
    }

    #[Test]
    public function recomputeOfAStartedTripSaysItIsLocked(): void
    {
        $this->seedTrip(startDate: new \DateTimeImmutable('today -1 day'));

        $response = $this->client->request('POST', \sprintf('/trips/%s/recompute', self::TRIP_ID), [
            'headers' => array_merge([
                'Content-Type' => 'application/ld+json',
                'If-Match' => '*',
            ], $this->authHeader($this->jwtToken)),
            'json' => ['modifications' => [['type' => 'pacing']]],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertTrue($response->toArray(false)['isLocked']);
    }

    /**
     * `POST /trips/{id}/analyze` had no functional coverage whatsoever.
     */
    #[Test]
    public function analyzeAnswersWithTheTripItAccepted(): void
    {
        $this->seedTrip(startDate: new \DateTimeImmutable('today -1 day'));

        $response = $this->client->request('POST', \sprintf('/trips/%s/analyze', self::TRIP_ID), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseStatusCodeSame(202);

        $data = $response->toArray(false);
        $this->assertSame(self::TRIP_ID, $data['id']);
        $this->assertArrayHasKey('route', $data['computationStatus']);
        $this->assertTrue($data['isLocked']);
    }

    /**
     * An accepted request points at the address where its outcome can be read — which only
     * answers in JSON since ADR-074.
     */
    #[Test]
    public function anAcceptedRequestPointsAtTheTrip(): void
    {
        $this->seedTrip();

        $this->client->request('POST', \sprintf('/trips/%s/analyze', self::TRIP_ID), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('Location', '/trips/'.self::TRIP_ID);
    }
}
