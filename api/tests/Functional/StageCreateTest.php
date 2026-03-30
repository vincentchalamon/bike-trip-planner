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
final class StageCreateTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000010';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    private function seedTripWithStages(string $tripId, int $stageCount = 3): void
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
            );
        }

        $repo->storeStages($tripId, $stages);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = $container->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations($tripId, ComputationName::cases());

        $this->associateTripWithUser($tripId, $this->testUser);
    }

    #[Test]
    public function createStageSuccess(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $response = $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'startPoint' => ['lat' => 44.0, 'lon' => 4.0, 'ele' => 200.0],
                'endPoint' => ['lat' => 44.5, 'lon' => 4.5, 'ele' => 300.0],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/stage-schema.json'));

        $data = $response->toArray(false);
        $this->assertSame('StageResponse', $data['@type']);
    }

    #[Test]
    public function createStageAtSpecificPosition(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $response = $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'position' => 1,
                'startPoint' => ['lat' => 44.0, 'lon' => 4.0, 'ele' => 200.0],
                'endPoint' => ['lat' => 44.5, 'lon' => 4.5, 'ele' => 300.0],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/stage-schema.json'));

        $data = $response->toArray(false);
        $this->assertSame('StageResponse', $data['@type']);
        $this->assertSame(2, $data['dayNumber']);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);

        $this->assertNotNull($stages);
        $this->assertCount(4, $stages);
        // New stage should be at position 1 (0-indexed)
        $this->assertSame(2, $stages[1]->dayNumber);
        $this->assertEqualsWithDelta(44.0, $stages[1]->startPoint->lat, 0.001);
    }

    #[Test]
    public function createStageAtPositionZero(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $response = $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'position' => 0,
                'startPoint' => ['lat' => 44.0, 'lon' => 4.0],
                'endPoint' => ['lat' => 44.5, 'lon' => 4.5],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/stage-schema.json'));

        $data = $response->toArray(false);
        $this->assertSame('StageResponse', $data['@type']);
        $this->assertSame(1, $data['dayNumber']);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);

        $this->assertNotNull($stages);
        $this->assertCount(4, $stages);
        // First stage should be the new one
        $this->assertSame(1, $stages[0]->dayNumber);
        $this->assertEqualsWithDelta(44.0, $stages[0]->startPoint->lat, 0.001);
    }

    #[Test]
    public function createStageWithLabel(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $response = $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'startPoint' => ['lat' => 44.0, 'lon' => 4.0],
                'endPoint' => ['lat' => 44.5, 'lon' => 4.5],
                'label' => 'Étape bonus : col du Galibier',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/stage-schema.json'));

        $data = $response->toArray(false);
        $this->assertSame('StageResponse', $data['@type']);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);

        $this->assertNotNull($stages);
        $lastStage = $stages[\count($stages) - 1];
        $this->assertSame('Étape bonus : col du Galibier', $lastStage->label);
    }

    #[Test]
    public function createStageDefaultsToEndPosition(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 2);

        $response = $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'startPoint' => ['lat' => 44.0, 'lon' => 4.0],
                'endPoint' => ['lat' => 44.5, 'lon' => 4.5],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/stage-schema.json'));

        $data = $response->toArray(false);
        $this->assertSame('StageResponse', $data['@type']);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);

        $this->assertNotNull($stages);
        $this->assertCount(3, $stages);
        // Last stage should be the new one
        $this->assertSame(3, $stages[2]->dayNumber);
    }

    #[Test]
    public function rejectsMissingStartPoint(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'endPoint' => ['lat' => 44.5, 'lon' => 4.5],
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/error-schema.json'));
        $this->assertJsonContains([
            'status' => 422,
            'detail' => 'startPoint and endPoint are required to create a stage.',
        ]);
    }

    #[Test]
    public function rejectsMissingEndPoint(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'startPoint' => ['lat' => 44.0, 'lon' => 4.0],
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/error-schema.json'));
        $this->assertJsonContains([
            'status' => 422,
            'detail' => 'startPoint and endPoint are required to create a stage.',
        ]);
    }

    #[Test]
    public function rejectsMissingBothPoints(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => new \stdClass(),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/error-schema.json'));
        $this->assertJsonContains([
            'status' => 422,
            'detail' => 'startPoint and endPoint are required to create a stage.',
        ]);
    }

    #[Test]
    public function rejectsPositionOutOfBounds(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 3);

        $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'position' => 10,
                'startPoint' => ['lat' => 44.0, 'lon' => 4.0],
                'endPoint' => ['lat' => 44.5, 'lon' => 4.5],
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/error-schema.json'));
        $this->assertJsonContains([
            'status' => 422,
            'detail' => 'Position 10 is out of bounds (0-3).',
        ]);
    }

    #[Test]
    public function rejectsNegativePosition(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'position' => -1,
                'startPoint' => ['lat' => 44.0, 'lon' => 4.0],
                'endPoint' => ['lat' => 44.5, 'lon' => 4.5],
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/validation-error-schema.json'));
        $this->assertJsonContains([
            'violations' => [
                ['propertyPath' => 'position'],
            ],
        ]);
    }

    #[Test]
    public function recalculateStagesMessageDispatched(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $response = $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'startPoint' => ['lat' => 44.0, 'lon' => 4.0],
                'endPoint' => ['lat' => 44.5, 'lon' => 4.5],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/stage-schema.json'));

        $data = $response->toArray(false);
        $this->assertSame('StageResponse', $data['@type']);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sentMessages = $transport->getSent();

        $messageClasses = array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()::class,
            $sentMessages,
        );

        $this->assertContains(RecalculateStages::class, $messageClasses);
    }

    #[Test]
    public function dayNumbersReindexedAfterInsert(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 3);

        $response = $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'position' => 1,
                'startPoint' => ['lat' => 44.0, 'lon' => 4.0],
                'endPoint' => ['lat' => 44.5, 'lon' => 4.5],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/stage-schema.json'));

        $data = $response->toArray(false);
        $this->assertSame('StageResponse', $data['@type']);
        $this->assertSame(2, $data['dayNumber']);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);

        $this->assertNotNull($stages);
        $this->assertCount(4, $stages);
        foreach ($stages as $i => $stage) {
            $this->assertSame($i + 1, $stage->dayNumber, \sprintf('Stage at index %d should have dayNumber %d', $i, $i + 1));
        }
    }
}
