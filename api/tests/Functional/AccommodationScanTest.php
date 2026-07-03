<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Object-level authorization on POST /trips/{tripId}/accommodations/scan
 * (audit finding SEC-002): the operation must be gated by trip ownership, and a
 * foreign trip is hidden as 404 (ADR-038), not silently scanned.
 */
#[ResetDatabase]
final class AccommodationScanTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000000a0';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('owner@example.com');
    }

    private function seedTrip(string $tripId): void
    {
        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        $repo->initializeTrip($tripId, $request);
        $repo->storeSourceType($tripId, SourceType::KOMOOT_TOUR->value);
        $repo->storeStages($tripId, [
            new Stage(
                tripId: $tripId,
                dayNumber: 1,
                distance: 80.0,
                elevation: 500.0,
                startPoint: new Coordinate(45.0, 5.0),
                endPoint: new Coordinate(45.5, 5.5),
                geometry: [new Coordinate(45.0, 5.0), new Coordinate(45.5, 5.5)],
                label: 'Stage 1',
            ),
        ]);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = $container->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations($tripId, ComputationName::cases());

        $this->associateTripWithUser($tripId, $this->testUser);
    }

    #[Test]
    public function ownerCanTriggerScan(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('POST', \sprintf('/trips/%s/accommodations/scan', self::TRIP_ID), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => ['radiusKm' => 5],
        ]);

        $this->assertResponseStatusCodeSame(202);
    }

    #[Test]
    public function foreignUserGets404(): void
    {
        // SEC-002 regression: a trip owned by someone else must not be scannable,
        // and is hidden as 404 (not 403) so its existence is not revealed (ADR-038).
        $this->seedTrip(self::TRIP_ID);

        ['token' => $intruderToken] = $this->createTestUserWithJwt('intruder@example.com');

        $this->client->request('POST', \sprintf('/trips/%s/accommodations/scan', self::TRIP_ID), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($intruderToken)),
            'json' => ['radiusKm' => 5],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function unauthenticatedRequestReturns401(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('POST', \sprintf('/trips/%s/accommodations/scan', self::TRIP_ID), [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['radiusKm' => 5],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }
}
