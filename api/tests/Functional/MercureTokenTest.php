<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class MercureTokenTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000401';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('owner@example.com');
    }

    private function seedTrip(string $tripId): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        /** @var DoctrineTripRequestRepository $repo */
        $repo = self::getContainer()->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip($tripId, $request);
        $this->associateTripWithUser($tripId, $this->testUser);
    }

    #[Test]
    public function ownerReceivesSubscriberTokenScopedToTheTrip(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $response = $this->client->request('GET', \sprintf('/trips/%s/mercure-token', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        $token = $response->toArray(false)['token'] ?? null;
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Decode with the same HS256 secret the hub uses and read the scope claim.
        $secret = $_SERVER['MERCURE_JWT_SECRET'] ?? $_ENV['MERCURE_JWT_SECRET'];
        $this->assertIsString($secret);
        $this->assertNotSame('', $secret);

        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
        $parsed = $config->parser()->parse($token);
        $this->assertInstanceOf(Plain::class, $parsed);
        $this->assertTrue(
            $config->validator()->validate($parsed, new SignedWith($config->signer(), $config->signingKey())),
            'The token must be signed with the Mercure hub secret.',
        );

        $mercure = $parsed->claims()->get('mercure');
        $this->assertIsArray($mercure);
        $this->assertSame([\sprintf('/trips/%s', self::TRIP_ID)], $mercure['subscribe'] ?? null);
    }

    #[Test]
    public function anotherUsersTripReturns404(): void
    {
        // Object-level authz denials are masked as 404, not 403 (ADR-038), so a
        // foreign trip is indistinguishable from a non-existent one.
        $this->seedTrip(self::TRIP_ID);

        ['token' => $intruderToken] = $this->createTestUserWithJwt('intruder@example.com');

        $this->client->request('GET', \sprintf('/trips/%s/mercure-token', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($intruderToken)),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function nonExistentTripReturns404(): void
    {
        $this->client->request('GET', '/trips/00000000-0000-0000-0000-000000000000/mercure-token', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function unauthenticatedRequestReturns401(): void
    {
        $this->client->request('GET', \sprintf('/trips/%s/mercure-token', self::TRIP_ID));

        $this->assertResponseStatusCodeSame(401);
    }
}
