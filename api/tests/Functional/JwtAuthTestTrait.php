<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ApiResource\TripRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Trip-ownership helpers for functional tests.
 *
 * The user and its JWT come from {@see \App\Tests\ApiTestCase::createAuthenticatedUser()};
 * this trait associates the seeded trip with that user so the voter grants access.
 */
trait JwtAuthTestTrait
{
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
