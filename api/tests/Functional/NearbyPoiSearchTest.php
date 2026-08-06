<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Functional coverage for `POST /trips/{id}/nearby-pois` (#934).
 *
 * Needs the real backend (Postgres + Redis) — CI is the real gate. The empty
 * Tier-1 coverage index in the test DB means the search short-circuits to
 * `outOfCoverage`, which is enough to exercise auth, the provider 404, the
 * denormalizer 400 and the radius clamp echo.
 */
final class NearbyPoiSearchTest extends ApiTestCase
{
    use ResetDatabase;
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-00000000934a';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('nearby@example.com');
    }

    private function seedTrip(string $tripId, User $owner): void
    {
        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $repo->initializeTrip($tripId, $request);
        $this->associateTripWithUser($tripId, $owner);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(mixed $category = 'water', ?int $radiusMeters = null): array
    {
        $body = ['category' => $category, 'position' => ['lat' => 48.0, 'lon' => 2.0]];
        if (null !== $radiusMeters) {
            $body['radiusMeters'] = $radiusMeters;
        }

        return $body;
    }

    #[Test]
    public function rejectsUnauthenticatedRequests(): void
    {
        $this->seedTrip(self::TRIP_ID, $this->testUser);

        $this->client->request('POST', \sprintf('/trips/%s/nearby-pois', self::TRIP_ID), [
            'json' => $this->body(),
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function rejectsRequestsFromANonOwnerWith403(): void
    {
        $other = new User('someone-else@example.com');
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($other);
        $em->flush();

        $this->seedTrip(self::TRIP_ID, $other);

        $this->client->request('POST', \sprintf('/trips/%s/nearby-pois', self::TRIP_ID), [
            'json' => $this->body(),
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function returns404ForAnUnknownTrip(): void
    {
        $this->client->request('POST', '/trips/00000000-0000-0000-0000-000000000000/nearby-pois', [
            'json' => $this->body(),
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function returns400ForAnUnknownCategory(): void
    {
        $this->seedTrip(self::TRIP_ID, $this->testUser);

        $this->client->request('POST', \sprintf('/trips/%s/nearby-pois', self::TRIP_ID), [
            'json' => $this->body('teleporter'),
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function clampsAnOutOfBoundsRadiusAndEchoesTheAppliedValue(): void
    {
        $this->seedTrip(self::TRIP_ID, $this->testUser);

        $response = $this->client->request('POST', \sprintf('/trips/%s/nearby-pois', self::TRIP_ID), [
            'json' => $this->body('water', 999_999),
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
        ]);

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray(false);
        $this->assertSame(self::TRIP_ID, $data['tripId']);
        $this->assertSame('water', $data['category']);
        $this->assertSame(20000, $data['radiusMeters']);
        $this->assertArrayHasKey('pois', $data);
    }
}
