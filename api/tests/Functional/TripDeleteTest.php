<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Uid\Uuid;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class TripDeleteTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000201';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    private function seedTrip(string $tripId): void
    {
        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        $container = self::getContainer();

        /** @var DoctrineTripRequestRepository $repo */
        $repo = $container->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip($tripId, $request);
        $this->associateTripWithUser($tripId, $this->testUser);
    }

    #[Test]
    public function deleteTripReturnsNoContent(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('DELETE', \sprintf('/trips/%s', self::TRIP_ID), [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function deleteTripRemovesItFromList(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request('DELETE', \sprintf('/trips/%s', self::TRIP_ID), [
            'headers' => $this->authHeader($this->jwtToken),
        ]);
        $this->assertResponseStatusCodeSame(204);

        $response = $this->client->request('GET', '/trips', [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);
        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $ids = array_column($data['member'], 'id');
        $this->assertNotContains(self::TRIP_ID, $ids);
    }

    #[Test]
    public function deleteNonExistentTripReturns404(): void
    {
        $this->client->request('DELETE', '/trips/00000000-0000-0000-0000-000000000000', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
