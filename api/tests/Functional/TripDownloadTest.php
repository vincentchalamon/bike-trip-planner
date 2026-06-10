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
final class TripDownloadTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000401';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    private function seedTripWithStages(string $tripId): void
    {
        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        $repo->initializeTrip($tripId, $request);
        $repo->storeTitle($tripId, 'Download test trip');
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
    public function ownerDownloadsGpx(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $response = $this->client->request('GET', \sprintf('/trips/%s.gpx', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/gpx+xml'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('<gpx', $response->getContent(false));
    }

    #[Test]
    public function ownerDownloadsFit(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $this->client->request('GET', \sprintf('/trips/%s.fit', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/vnd.ant.fit'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function foreignUserDownloadReturns404(): void
    {
        // Object-level authz denial is surfaced as 404 (ADR-038), not 403.
        $this->seedTripWithStages(self::TRIP_ID);

        ['token' => $otherToken] = $this->createTestUserWithJwt('intruder@example.com');

        $this->client->request('GET', \sprintf('/trips/%s.gpx', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/gpx+xml'], $this->authHeader($otherToken)),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function unauthenticatedDownloadReturns401(): void
    {
        $this->client->request('GET', \sprintf('/trips/%s.gpx', self::TRIP_ID), [
            'headers' => ['Accept' => 'application/gpx+xml'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }
}
