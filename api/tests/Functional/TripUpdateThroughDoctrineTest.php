<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\ApiTestCase;
use ApiPlatform\Test\Client;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Message\GenerateStages;
use App\Repository\DoctrineTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * `PATCH /trips/{id}` over the repository production actually runs on.
 *
 * The update suite used to run against a transient repository, which deserialised a fresh
 * copy per read. Doctrine does the opposite: it serves the managed entity from the
 * identity map, and API Platform deserialises the PATCH body into that very instance
 * (ReadProvider hands the operation provider's result to DeserializeProvider as
 * OBJECT_TO_POPULATE). Any comparison made against a second `getRequest()` therefore compared
 * the new settings with themselves and answered "nothing changed" — for every PATCH, in
 * production, invisibly, since no functional test ever took this path.
 *
 * The alias is gone, so every functional test takes this path now; this one remains the test
 * that names the reason.
 */
#[ResetDatabase]
final class TripUpdateThroughDoctrineTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000004d1';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();

        ['user' => $this->testUser, 'jwt' => $this->jwtToken] = $this->createAuthenticatedUser('doctrine-patch@example.com');
    }

    #[Test]
    public function aPacingEditDispatchesItsComputations(): void
    {
        $this->seedTripThroughDoctrine();

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID, [
            'headers' => array_merge(
                ['Content-Type' => 'application/merge-patch+json', 'If-Match' => '"1"'],
                $this->authHeader($this->jwtToken),
            ),
            'json' => ['fatigueFactor' => 0.75],
        ]);

        $this->assertResponseStatusCodeSame(202);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $dispatched = array_map(
            static fn (object $envelope): object => $envelope->getMessage(),
            $transport->getSent(),
        );

        $this->assertNotSame(
            [],
            array_filter($dispatched, static fn (object $m): bool => $m instanceof GenerateStages),
            'A pacing edit must re-dispatch the stages computation. Resolving against a second getRequest() compares the managed entity with itself and dispatches nothing.',
        );
    }

    /**
     * Seeds through the interface the processor reads, which resolves to the Doctrine repository.
     */
    private function seedTripThroughDoctrine(): void
    {
        $container = self::getContainer();

        $repository = $container->get(TripRequestRepositoryInterface::class);
        \assert($repository instanceof DoctrineTripRequestRepository);

        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->fatigueFactor = 0.9;
        $request->elevationPenalty = 50.0;

        $repository->initializeTrip(self::TRIP_ID, $request);

        $this->associateTripWithUser(self::TRIP_ID, $this->testUser);

        $tracker = $container->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, ComputationName::pipeline());
    }
}
