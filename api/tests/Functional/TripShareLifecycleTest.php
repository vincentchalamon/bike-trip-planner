<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TripShareLifecycleTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000901';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('share@example.com');
    }

    private function seedTripWithStages(string $tripId): void
    {
        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        $repo->initializeTrip($tripId, $request);
        $repo->storeTitle($tripId, 'Share lifecycle trip');
        $this->associateTripWithUser($tripId, $this->testUser);

        $stage = new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1200.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.5, 6.5, 800.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0), new Coordinate(45.5, 6.5, 800.0)],
        );
        $repo->storeStages($tripId, [$stage]);
    }

    #[Test]
    public function revokeReturns204(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $this->client->request('POST', \sprintf('/trips/%s/share', self::TRIP_ID), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', \sprintf('/trips/%s/share', self::TRIP_ID), [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function recreateAfterRevokeYieldsAValidActiveShare(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $first = $this->client->request('POST', \sprintf('/trips/%s/share', self::TRIP_ID), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [],
        ]);
        $this->assertResponseStatusCodeSame(201);
        /** @var array{shortCode: string} $firstBody */
        $firstBody = $first->toArray();

        $this->client->request('DELETE', \sprintf('/trips/%s/share', self::TRIP_ID), [
            'headers' => $this->authHeader($this->jwtToken),
        ]);
        $this->assertResponseStatusCodeSame(204);

        // Recreating after revocation must succeed (no 409 from the soft-deleted row).
        $second = $this->client->request('POST', \sprintf('/trips/%s/share', self::TRIP_ID), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [],
        ]);
        $this->assertResponseStatusCodeSame(201);
        /** @var array{shortCode: string, active: bool} $secondBody */
        $secondBody = $second->toArray();

        // The recreated link is a brand-new active share, not the revoked one.
        $this->assertNotSame($firstBody['shortCode'], $secondBody['shortCode']);
        $this->assertTrue($secondBody['active']);

        // The owner endpoint now resolves the trip to the fresh active share.
        $active = $this->client->request('GET', \sprintf('/trips/%s/share', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);
        $this->assertResponseIsSuccessful();
        /** @var array{shortCode: string} $activeBody */
        $activeBody = $active->toArray();
        $this->assertSame($secondBody['shortCode'], $activeBody['shortCode']);
    }
}
