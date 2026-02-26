<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class StageUpdateTest extends ApiTestCase
{
    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000020';

    private function seedTripWithStages(string $tripId, int $stageCount = 3): void
    {
        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest();
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
                label: \sprintf('Étape %d', $i + 1),
            );
        }

        $repo->storeStages($tripId, $stages);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = $container->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations($tripId, ComputationName::cases());
    }

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    #[Test]
    public function updateStageLabel(): void
    {
        self::createClient();
        $this->seedTripWithStages(self::TRIP_ID);

        self::createClient()->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'label' => 'Grenoble → Briançon',
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
        $this->assertSame('Grenoble → Briançon', $stages[0]->label);
    }

    #[Test]
    public function updateStageStartPoint(): void
    {
        self::createClient();
        $this->seedTripWithStages(self::TRIP_ID);

        self::createClient()->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'startPoint' => ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
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
        $this->assertEqualsWithDelta(48.8566, $stages[0]->startPoint->lat, 0.0001);
        $this->assertEqualsWithDelta(2.3522, $stages[0]->startPoint->lon, 0.0001);
    }

    #[Test]
    public function updateStageEndPoint(): void
    {
        self::createClient();
        $this->seedTripWithStages(self::TRIP_ID);

        self::createClient()->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/1', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'endPoint' => ['lat' => 44.0, 'lon' => 6.0, 'ele' => 1200.0],
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
        $this->assertEqualsWithDelta(44.0, $stages[1]->endPoint->lat, 0.001);
    }

    #[Test]
    public function updateStageRecalculatesDistanceWhenPointsChange(): void
    {
        self::createClient();
        $this->seedTripWithStages(self::TRIP_ID);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stagesBefore = $repo->getStages(self::TRIP_ID);
        $this->assertNotNull($stagesBefore);
        $distanceBefore = $stagesBefore[0]->distance;

        self::createClient()->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'endPoint' => ['lat' => 48.0, 'lon' => 8.0],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        $this->assertMatchesJsonSchema((string) file_get_contents(__DIR__.'/stage-schema.json'));
        // todo check response content

        $stagesAfter = $repo->getStages(self::TRIP_ID);
        $this->assertNotNull($stagesAfter);
        $this->assertNotEqualsWithDelta($distanceBefore, $stagesAfter[0]->distance, 0.1);
    }

    #[Test]
    public function updateStageDispatchesRecalculate(): void
    {
        self::createClient();
        $this->seedTripWithStages(self::TRIP_ID);

        self::createClient()->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'label' => 'Updated label',
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
    public function stageNotFound(): void
    {
        self::createClient();
        $this->seedTripWithStages(self::TRIP_ID, 3);

        self::createClient()->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/99', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'label' => 'Ghost stage',
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
