<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Message\AnalyzeTerrain;
use App\Message\CheckBikeShops;
use App\Message\CheckBorderCrossing;
use App\Message\CheckCalendar;
use App\Message\CheckCulturalPois;
use App\Message\CheckHealthServices;
use App\Message\CheckRailwayStations;
use App\Message\CheckWaterPoints;
use App\Message\FetchWeather;
use App\Message\ScanAccommodations;
use App\Message\ScanEvents;
use App\Message\ScanPois;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TripAnalyzeTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000042';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    /**
     * Seeds a trip with pre-computed stages and a fresh computation tracker
     * where ROUTE and STAGES are already done (preview phase complete).
     */
    private function seedTripReadyForAnalysis(string $tripId, int $stageCount = 3): void
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
                distance: 80.0,
                elevation: 500.0,
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
        $tracker->initializeComputations($tripId, ComputationName::pipeline());
        $tracker->markDone($tripId, ComputationName::ROUTE);
        $tracker->markDone($tripId, ComputationName::STAGES);

        /** @var TripGenerationTrackerInterface $generationTracker */
        $generationTracker = $container->get(TripGenerationTrackerInterface::class);
        $generationTracker->initialize($tripId);

        $this->associateTripWithUser($tripId, $this->testUser);
    }

    private function seedTripWithoutStages(string $tripId): void
    {
        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        $repo->initializeTrip($tripId, $request);
        $repo->storeSourceType($tripId, SourceType::KOMOOT_TOUR->value);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = $container->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations($tripId, ComputationName::pipeline());

        /** @var TripGenerationTrackerInterface $generationTracker */
        $generationTracker = $container->get(TripGenerationTrackerInterface::class);
        $generationTracker->initialize($tripId);

        $this->associateTripWithUser($tripId, $this->testUser);
    }

    #[Test]
    public function analyzeTripReturns202(): void
    {
        $this->seedTripReadyForAnalysis(self::TRIP_ID);

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/analyze', self::TRIP_ID),
            ['headers' => [...['Content-Type' => 'application/ld+json'], ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');

        $data = $response->toArray(false);
        $this->assertSame(self::TRIP_ID, $data['id']);
        $this->assertSame('Trip', $data['@type']);
        $this->assertArrayHasKey('computationStatus', $data);
    }

    #[Test]
    public function analyzeDispatchesEveryEnrichmentMessage(): void
    {
        $this->seedTripReadyForAnalysis(self::TRIP_ID);

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/analyze', self::TRIP_ID),
            ['headers' => [...['Content-Type' => 'application/ld+json'], ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseStatusCodeSame(202);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $dispatched = array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()::class,
            $transport->getSent(),
        );

        $expected = [
            ScanPois::class,
            ScanAccommodations::class,
            AnalyzeTerrain::class,
            FetchWeather::class,
            CheckCalendar::class,
            CheckBikeShops::class,
            CheckWaterPoints::class,
            CheckHealthServices::class,
            CheckCulturalPois::class,
            CheckRailwayStations::class,
            CheckBorderCrossing::class,
            ScanEvents::class,
        ];

        $this->assertSame($expected, $dispatched, 'Exactly one message per enrichment step must be dispatched, in order.');
    }

    #[Test]
    public function analyzeResetsEnrichmentComputationsToPending(): void
    {
        $this->seedTripReadyForAnalysis(self::TRIP_ID);

        $response = $this->client->request(
            'POST',
            \sprintf('/trips/%s/analyze', self::TRIP_ID),
            ['headers' => [...['Content-Type' => 'application/ld+json'], ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseStatusCodeSame(202);

        $data = $response->toArray(false);
        // Preview-phase computations stay done
        $this->assertSame('done', $data['computationStatus']['route']);
        $this->assertSame('done', $data['computationStatus']['stages']);
        // Enrichment computations are re-armed
        $this->assertSame('pending', $data['computationStatus']['osm_scan']);
        $this->assertSame('pending', $data['computationStatus']['pois']);
        $this->assertSame('pending', $data['computationStatus']['weather']);
        $this->assertSame('pending', $data['computationStatus']['terrain']);
    }

    #[Test]
    public function analyzeWithoutStagesReturns422(): void
    {
        $this->seedTripWithoutStages(self::TRIP_ID);

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/analyze', self::TRIP_ID),
            ['headers' => [...['Content-Type' => 'application/ld+json'], ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function analyzeWhileAnalysisInProgressReturns409(): void
    {
        $this->seedTripReadyForAnalysis(self::TRIP_ID);

        // Simulate an in-flight analysis by marking an enrichment computation as running.
        /** @var ComputationTrackerInterface $tracker */
        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        $tracker->markRunning(self::TRIP_ID, ComputationName::WEATHER);

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/analyze', self::TRIP_ID),
            ['headers' => [...['Content-Type' => 'application/ld+json'], ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseStatusCodeSame(409);
    }

    #[Test]
    public function analyzeNonExistentTripReturns404(): void
    {
        $this->client->request(
            'POST',
            '/trips/00000000-0000-0000-0000-000000000000/analyze',
            ['headers' => [...['Content-Type' => 'application/ld+json'], ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function analyzeRequiresAuthentication(): void
    {
        $this->seedTripReadyForAnalysis(self::TRIP_ID);

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/analyze', self::TRIP_ID),
            ['headers' => ['Content-Type' => 'application/ld+json']],
        );

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function analyzeRejectsTripOwnedByAnotherUser(): void
    {
        $this->seedTripReadyForAnalysis(self::TRIP_ID);

        ['token' => $otherToken] = $this->createTestUserWithJwt('other@example.com');

        $this->client->request(
            'POST',
            \sprintf('/trips/%s/analyze', self::TRIP_ID),
            ['headers' => [...['Content-Type' => 'application/ld+json'], ...$this->authHeader($otherToken)]],
        );

        $this->assertResponseStatusCodeSame(403);
    }
}
