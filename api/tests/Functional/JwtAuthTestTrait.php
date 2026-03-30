<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ApiResource\TripRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Provides JWT-based authentication helpers for functional tests.
 *
 * Tests that exercise endpoints protected by is_granted('ROLE_USER') or trip
 * ownership voters must use this trait to obtain a valid JWT and, when needed,
 * associate the seeded trip with the test user so the voter grants access.
 */
trait JwtAuthTestTrait
{
    /**
     * Creates a User in the database and returns it together with a signed JWT.
     *
     * @param non-empty-string $email
     *
     * @return array{user: User, token: string}
     */
    private function createTestUserWithJwt(string $email = 'test@example.com'): array
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        $user = new User($email);
        $em->persist($user);
        $em->flush();

        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $token = $jwtManager->create($user);

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Associates a seeded trip with the given user by writing to PostgreSQL.
     *
     * The trip exists in Redis (via RedisTripRequestRepository) but TripVoter
     * checks PostgreSQL for ownership. This creates a minimal TripRequest row
     * in PostgreSQL so the voter grants access.
     *
     * IMPORTANT: The caller must use the SAME kernel (no createClient() between
     * seedTrip and the HTTP request) so the trip remains in the ArrayAdapter.
     */
    private function associateTripWithUser(string $tripId, User $user): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        $tripUuid = Uuid::fromString($tripId);
        $existing = $em->find(TripRequest::class, $tripUuid);

        if ($existing instanceof TripRequest) {
            $existing->user = $user;
        } else {
            $tripRequest = new TripRequest($tripUuid);
            $tripRequest->user = $user;
            $em->persist($tripRequest);
        }

        $em->flush();
    }

    /**
     * Returns an Authorization header array for use in request options.
     *
     * @return array<string, string>
     */
    private function authHeader(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
