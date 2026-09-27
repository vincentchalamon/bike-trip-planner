<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Uid\Uuid;
use App\Tests\ApiTestCase;
use ApiPlatform\Test\Client;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class TripDeleteTest extends ApiTestCase
{
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

    #[Test]
    public function deleteForeignTripReturns404(): void
    {
        // Deleting another user's trip is hidden as 404, not 403, so its existence is
        // not revealed by enumeration (ADR-038).
        $this->seedTrip(self::TRIP_ID);

        ['token' => $otherToken] = $this->createTestUserWithJwt('intruder@example.com');

        $this->client->request('DELETE', \sprintf('/trips/%s', self::TRIP_ID), [
            'headers' => $this->authHeader($otherToken),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * The provider runs before the voter, so a missing trip is reported by the provider and a
     * foreign one by the masked denial (ADR-038). Two different bodies would tell them apart.
     */
    #[Test]
    public function missingAndForeignTripsAnswerTheSame404(): void
    {
        $this->seedTrip(self::TRIP_ID);
        ['token' => $otherToken] = $this->createTestUserWithJwt('intruder@example.com');

        $response = $this->client->request('DELETE', '/trips/00000000-0000-0000-0000-000000000000', [
            'headers' => $this->authHeader($otherToken),
        ]);
        $this->assertResponseStatusCodeSame(404);
        $missing = $this->withoutTrace($response->toArray(false));

        $response = $this->client->request('DELETE', \sprintf('/trips/%s', self::TRIP_ID), [
            'headers' => $this->authHeader($otherToken),
        ]);
        $this->assertResponseStatusCodeSame(404);
        $foreign = $this->withoutTrace($response->toArray(false));

        $this->assertSame('Trip not found or has expired.', $missing['detail'] ?? null);
        $this->assertSame($missing, $foreign);
    }

    /**
     * The error body without the debug-only trace, which differs by construction.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function withoutTrace(array $body): array
    {
        unset($body['trace']);

        return $body;
    }
}
