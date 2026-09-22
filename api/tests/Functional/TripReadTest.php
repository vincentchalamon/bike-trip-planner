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
 * `GET /trips/{id}` — the canonical read (ADR-074).
 *
 * The operation declared gpx and fit only, so this address answered 406 to
 * `application/ld+json`: the `@id` every write response hands out was not dereferenceable, and
 * no test had ever asked it for JSON. `TransportAgnosticAuthorizationTest` documented the 406
 * as a fact it had to work around.
 */
#[ResetDatabase]
final class TripReadTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000801';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    private function seedTrip(string $tripId, ?\DateTimeImmutable $startDate = null, bool $withStages = true): void
    {
        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = $startDate;

        $repo->initializeTrip($tripId, $request);
        $this->associateTripWithUser($tripId, $this->testUser);

        if ($withStages) {
            $repo->storeStages($tripId, [new StageDto(
                tripId: $tripId,
                dayNumber: 1,
                distance: 85.5,
                elevation: 1200.0,
                startPoint: new Coordinate(45.0, 6.0, 1000.0),
                endPoint: new Coordinate(45.5, 6.5, 800.0),
                geometry: [new Coordinate(45.0, 6.0, 1000.0)],
            )]);
        }
    }

    #[Test]
    public function theAddressEveryWriteResponseHandsOutAnswersInJson(): void
    {
        $this->seedTrip(self::TRIP_ID);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations(self::TRIP_ID, [ComputationName::ROUTE]);
        $tracker->markDone(self::TRIP_ID, ComputationName::ROUTE);

        $response = $this->client->request('GET', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/ld+json');

        $data = $response->toArray(false);
        $this->assertSame(self::TRIP_ID, $data['id']);
        $this->assertSame('/trips/'.self::TRIP_ID, $data['@id'], 'The IRI is its own address.');
        $this->assertSame(['route' => 'done'], $data['computationStatus']);
        $this->assertFalse($data['isLocked']);
    }

    /**
     * A trip whose stages have not been computed yet is an ordinary trip — `POST /trips` hands
     * out its `@id` before any stage exists. The export keeps refusing it, because a file with
     * no stages in it is not a file.
     */
    #[Test]
    public function aTripWithoutStagesReadsButDoesNotExport(): void
    {
        $this->seedTrip(self::TRIP_ID, withStages: false);

        $this->client->request('GET', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', \sprintf('/trips/%s.gpx', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/gpx+xml'], $this->authHeader($this->jwtToken)),
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aLockedTripSaysSo(): void
    {
        $this->seedTrip(self::TRIP_ID, startDate: new \DateTimeImmutable('today -3 days'));

        $response = $this->client->request('GET', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($response->toArray(false)['isLocked']);
    }

    /**
     * The denial is now reachable in JSON. It used to be masked by a 406 raised during content
     * negotiation, ahead of the security stage.
     */
    #[Test]
    public function aStrangerGetsTheSame404AsForAMissingTrip(): void
    {
        $this->seedTrip(self::TRIP_ID);
        ['token' => $intruderToken] = $this->createTestUserWithJwt('intruder@example.com');

        $this->client->request('GET', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($intruderToken)),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
