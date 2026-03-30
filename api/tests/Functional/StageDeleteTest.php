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
final class StageDeleteTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000040';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    private function seedTripWithStages(string $tripId, int $stageCount = 4, string $sourceType = SourceType::KOMOOT_TOUR->value): void
    {
        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = new \DateTimeImmutable('2026-07-01');

        $repo->initializeTrip($tripId, $request);
        $repo->storeSourceType($tripId, $sourceType);

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
    public function deleteMiddleStageContinuousRoute(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 4, SourceType::KOMOOT_TOUR->value);

        $this->client->request('DELETE', '/trips/'.self::TRIP_ID.'/stages/1', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(202);
        // todo check json schema
        // todo check response content

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);

        $this->assertNotNull($stages);
        $this->assertCount(3, $stages);

        // Day numbers should be re-indexed
        foreach ($stages as $i => $stage) {
            $this->assertSame($i + 1, $stage->dayNumber);
        }
    }

    #[Test]
    public function deleteLastStageMergesWithPrevious(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 4, SourceType::KOMOOT_TOUR->value);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stagesBefore = $repo->getStages(self::TRIP_ID);
        $this->assertNotNull($stagesBefore);
        $lastStageEndPoint = $stagesBefore[3]->endPoint;

        $this->client->request('DELETE', '/trips/'.self::TRIP_ID.'/stages/3', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(202);
        // todo check json schema
        // todo check response content

        $stagesAfter = $repo->getStages(self::TRIP_ID);
        $this->assertNotNull($stagesAfter);
        $this->assertCount(3, $stagesAfter);

        // The previous stage (now last) should have the deleted stage's endPoint
        $newLastStage = $stagesAfter[2];
        $this->assertEqualsWithDelta($lastStageEndPoint->lat, $newLastStage->endPoint->lat, 0.001);
        $this->assertEqualsWithDelta($lastStageEndPoint->lon, $newLastStage->endPoint->lon, 0.001);
    }

    #[Test]
    public function deleteFirstStageMergesWithNext(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 4, SourceType::KOMOOT_TOUR->value);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stagesBefore = $repo->getStages(self::TRIP_ID);
        $this->assertNotNull($stagesBefore);
        $firstStageStartPoint = $stagesBefore[0]->startPoint;

        $this->client->request('DELETE', '/trips/'.self::TRIP_ID.'/stages/0', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(202);
        // todo check json schema
        // todo check response content

        $stagesAfter = $repo->getStages(self::TRIP_ID);
        $this->assertNotNull($stagesAfter);
        $this->assertCount(3, $stagesAfter);

        // The next stage (now first) should have the deleted stage's startPoint
        $newFirstStage = $stagesAfter[0];
        $this->assertEqualsWithDelta($firstStageStartPoint->lat, $newFirstStage->startPoint->lat, 0.001);
        $this->assertEqualsWithDelta($firstStageStartPoint->lon, $newFirstStage->startPoint->lon, 0.001);
    }

    #[Test]
    public function deleteFromCollectionRemovesWithoutMerge(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 4, SourceType::KOMOOT_COLLECTION->value);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stagesBefore = $repo->getStages(self::TRIP_ID);
        $this->assertNotNull($stagesBefore);
        $secondStageLabel = $stagesBefore[1]->label;

        $this->client->request('DELETE', '/trips/'.self::TRIP_ID.'/stages/0', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(202);
        // todo check json schema
        // todo check response content

        $stagesAfter = $repo->getStages(self::TRIP_ID);
        $this->assertNotNull($stagesAfter);
        $this->assertCount(3, $stagesAfter);

        // The second stage (now first) should keep its original data
        $this->assertSame($secondStageLabel, $stagesAfter[0]->label);
    }

    #[Test]
    public function rejectsDeleteWhenOnly2StagesRemain(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 2);

        $this->client->request('DELETE', '/trips/'.self::TRIP_ID.'/stages/0', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertJsonContains([
            'status' => 422,
            'detail' => 'Cannot delete stage: minimum 2 stages required.',
        ]);
    }

    #[Test]
    public function stageNotFound(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 3);

        $this->client->request('DELETE', '/trips/'.self::TRIP_ID.'/stages/99', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(404);
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/error-schema.json'));
        $this->assertJsonContains([
            'status' => 404,
            'detail' => 'Stage at index 99 not found.',
        ]);
    }

    #[Test]
    public function deleteDispatchesRecalculateStages(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 4);

        $this->client->request('DELETE', '/trips/'.self::TRIP_ID.'/stages/1', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(202);
        // todo check json schema
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
    public function dayNumbersReindexedAfterDelete(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 5);

        $this->client->request('DELETE', '/trips/'.self::TRIP_ID.'/stages/2', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(202);
        // todo check json schema
        // todo check response content

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
