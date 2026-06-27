<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class RestDayInsertTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000050';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    private function seedTripWithStages(string $tripId, int $stageCount = 2): void
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
    public function insertRestDaySuccess(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 2);

        $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages/0/rest-day', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(202);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);

        $this->assertNotNull($stages);
        $this->assertCount(3, $stages);
        $this->assertTrue($stages[1]->isRestDay);
    }

    #[Test]
    public function extendsEndDateToMatchStageCount(): void
    {
        $this->seedTripWithStages(self::TRIP_ID, 2);

        $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages/0/rest-day', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(202);

        // 2 → 3 stages: the trip now spans 3 days, so the end date shifts from
        // 2026-07-02 to 2026-07-03 (recette #649).
        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $request = $repo->getRequest(self::TRIP_ID);

        $this->assertNotNull($request);
        $this->assertSame('2026-07-03', $request->endDate?->format('Y-m-d'));
    }
}
