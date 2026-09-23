<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\ApiTestCase;
use ApiPlatform\Test\Client;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Symfony\Component\Uid\Uuid;

/**
 * What a trip already under way refuses, and what it must keep accepting.
 *
 * 423 had eleven unit assertions and not one over HTTP, and it appeared nowhere in the
 * published contract — while the mobile client had been mapping it to a "locked" failure all
 * along. These are the first end-to-end assertions that the status is real.
 *
 * The negative cases matter as much: the lock never lifts, so anything refused here is refused
 * for the rest of the trip's life.
 */
#[ResetDatabase]
final class TripLockedTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000004e1';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('locked@example.com');
    }

    #[Test]
    public function analysisIsRefusedOnAStartedTrip(): void
    {
        $this->seedStartedTrip();

        $this->client->request('POST', \sprintf('/trips/%s/analyze', self::TRIP_ID), $this->asOwner());

        $this->assertResponseStatusCodeSame(423);
    }

    #[Test]
    public function anAccommodationRescanIsRefusedOnAStartedTrip(): void
    {
        $this->seedStartedTrip();

        $this->client->request('POST', \sprintf('/trips/%s/accommodations/scan', self::TRIP_ID), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => ['searchRadiusKm' => 5],
        ]);

        $this->assertResponseStatusCodeSame(423);
    }

    #[Test]
    public function settingsAreRefusedOnAStartedTrip(): void
    {
        $this->seedStartedTrip();

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(
                ['Content-Type' => 'application/merge-patch+json', 'If-Match' => '*'],
                $this->authHeader($this->jwtToken),
            ),
            'json' => ['fatigueFactor' => 0.75],
        ]);

        $this->assertResponseStatusCodeSame(423);
    }

    /**
     * A started trip cannot be edited back into the future. The lock reads the stored start
     * date, never the one the body proposes — otherwise every locked trip carried its own key.
     */
    #[Test]
    public function aStartedTripCannotUnlockItselfByMovingItsStartDate(): void
    {
        $this->seedStartedTrip();

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(
                ['Content-Type' => 'application/merge-patch+json', 'If-Match' => '*'],
                $this->authHeader($this->jwtToken),
            ),
            'json' => ['startDate' => '2099-01-01T00:00:00+00:00'],
        ]);

        $this->assertResponseStatusCodeSame(423);
    }

    /**
     * The lock is monotonic: `startDate <= today` never becomes false again. Refusing a delete
     * would therefore make every past trip permanently undeletable, which collides head-on with
     * account erasure.
     */
    #[Test]
    public function aStartedTripCanStillBeDeleted(): void
    {
        $this->seedStartedTrip();

        $this->client->request('DELETE', '/trips/'.self::TRIP_ID, $this->asOwner());

        $this->assertResponseStatusCodeSame(204);
    }

    /**
     * `/recompute` is the only way to settle a pipeline left half-finished, and a trip becomes
     * locked by the mere passing of midnight. Refusing it would freeze such a trip at `pending`
     * for good, with the completion gate never closing again.
     */
    #[Test]
    public function aStartedTripCanStillBeRecomputed(): void
    {
        $this->seedStartedTrip();

        $this->client->request('POST', \sprintf('/trips/%s/recompute', self::TRIP_ID), [
            'headers' => array_merge(
                ['Content-Type' => 'application/ld+json', 'If-Match' => '*'],
                $this->authHeader($this->jwtToken),
            ),
            'json' => ['modifications' => [['type' => 'pacing']]],
        ]);

        // What is under test is the lock, not the endpoint's own preconditions: this trip has
        // no stages, so it answers 422 on its merits. Reaching that answer at all is the proof
        // — a refused trip would never have got past the decorator.
        $this->assertResponseStatusCodeSame(422);
    }

    /**
     * Sharing a trip while riding it is the point of sharing.
     */
    #[Test]
    public function aStartedTripCanStillBeShared(): void
    {
        $this->seedStartedTrip();

        $this->client->request('POST', \sprintf('/trips/%s/share', self::TRIP_ID), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [],
        ]);

        $this->assertResponseStatusCodeSame(201);
    }

    /**
     * @return array{headers: array<string, string>}
     */
    private function asOwner(): array
    {
        return ['headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken))];
    }

    private function seedStartedTrip(): void
    {
        $container = self::getContainer();

        $repository = $container->get(TripRequestRepositoryInterface::class);
        \assert($repository instanceof TripRequestRepositoryInterface);

        $request = new TripRequest(Uuid::fromString(self::TRIP_ID));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = new \DateTimeImmutable('today -1 day');

        $repository->initializeTrip(self::TRIP_ID, $request);

        $this->associateTripWithUser(self::TRIP_ID, $this->testUser);

        $tracker = $container->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, ComputationName::pipeline());
    }
}
