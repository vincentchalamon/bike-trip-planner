<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Uid\Uuid;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class StageMoveTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000030';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    private function seedTripWithStages(string $tripId, int $stageCount = 4): void
    {
        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = new \DateTimeImmutable('2026-07-01');

        $repo->initializeTrip($tripId, $request);
        $repo->storeSourceType($tripId, SourceType::KOMOOT_TOUR->value);

        $stages = [];
        for ($i = 0; $i < $stageCount; ++$i) {
            $stages[] = new Stage(
                tripId: $tripId,
                dayNumber: $i + 1,
                distance: 80.0 + $i * 5,
                elevation: 500.0 + $i * 100,
                startPoint: new Coordinate(45.0 + $i * 0.5, 5.0 + $i * 0.5),
                endPoint: new Coordinate(45.0 + ($i + 1) * 0.5, 5.0 + ($i + 1) * 0.5),
                geometry: [
                    new Coordinate(45.0 + $i * 0.5, 5.0 + $i * 0.5),
                    new Coordinate(45.0 + ($i + 1) * 0.5, 5.0 + ($i + 1) * 0.5),
                ],
                label: \sprintf('Stage %d', $i + 1),
            );
        }

        $repo->storeStages($tripId, $stages);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = $container->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations($tripId, ComputationName::cases());

        $this->associateTripWithUser($tripId, $this->testUser);
    }

    #[Test]
    public function moveStageForward(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 4);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0/move', [
            'headers' => ['Content-Type' => 'application/merge-patch+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'toIndex' => 2,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/stage-schema.json'));
        // todo check response content

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);

        $this->assertNotNull($stages);
        $this->assertCount(4, $stages);
        // Original Stage 1 (label "Stage 1") should now be at index 2
        $this->assertSame('Stage 1', $stages[2]->label);
        // Day numbers should be re-indexed
        foreach ($stages as $i => $stage) {
            $this->assertSame($i + 1, $stage->dayNumber);
        }
    }

    #[Test]
    public function moveStageBackward(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 4);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/3/move', [
            'headers' => ['Content-Type' => 'application/merge-patch+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'toIndex' => 0,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/stage-schema.json'));
        // todo check response content

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);

        $this->assertNotNull($stages);
        // Original Stage 4 should now be at index 0
        $this->assertSame('Stage 4', $stages[0]->label);
    }

    #[Test]
    public function moveStageDispatchesRecalculate(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 3);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0/move', [
            'headers' => ['Content-Type' => 'application/merge-patch+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'toIndex' => 2,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/stage-schema.json'));
        // todo check response content

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messageClasses = array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()::class,
            $transport->getSent(),
        );

        $this->assertContains(RecalculateStages::class, $messageClasses);
    }

    #[Test]
    public function rejectsMissingToIndex(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0/move', [
            'headers' => ['Content-Type' => 'application/merge-patch+json', ...$this->authHeader($this->jwtToken)],
            'json' => new \stdClass(),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/error-schema.json'));
        $this->assertJsonContains([
            'status' => 422,
            'detail' => 'toIndex is required.',
        ]);
    }

    #[Test]
    public function rejectsToIndexOutOfBounds(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 3);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0/move', [
            'headers' => ['Content-Type' => 'application/merge-patch+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'toIndex' => 10,
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/error-schema.json'));
        $this->assertJsonContains([
            'status' => 422,
            'detail' => 'toIndex 10 is out of bounds (0-2).',
        ]);
    }

    #[Test]
    public function rejectsSameIndex(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/1/move', [
            'headers' => ['Content-Type' => 'application/merge-patch+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'toIndex' => 1,
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/error-schema.json'));
        $this->assertJsonContains([
            'status' => 422,
            'detail' => 'toIndex must be different from current index.',
        ]);
    }

    #[Test]
    public function rejectsNegativeToIndex(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0/move', [
            'headers' => ['Content-Type' => 'application/merge-patch+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'toIndex' => -1,
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/validation-error-schema.json'));
        $this->assertJsonContains([
            'violations' => [
                ['propertyPath' => 'toIndex'],
            ],
        ]);
    }

    #[Test]
    public function stageNotFound(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 3);

        $this->client->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/99/move', [
            'headers' => ['Content-Type' => 'application/merge-patch+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'toIndex' => 0,
            ],
        ]);

        $this->assertResponseStatusCodeSame(404);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/error-schema.json'));
        $this->assertJsonContains([
            'status' => 404,
            'detail' => 'Stage at index 99 not found.',
        ]);
    }
}
