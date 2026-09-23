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
     * Gives a seeded trip an owner, so TripVoter grants access to it.
     *
     * Kept as its own step now that the repository writes to PostgreSQL too: seeding creates
     * the row, this attaches the user. Call order matters — `initializeTrip()` on a row that
     * already exists copies only the settings fields, so seed first and associate after, or
     * the title and locale are lost.
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
