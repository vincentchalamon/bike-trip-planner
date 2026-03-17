<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Message\ScanAccommodations;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class StageSelectAccommodationTest extends ApiTestCase
{
    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000039';

    private function seedTripWithStages(string $tripId): void
    {
        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/987654321';
        $request->startDate = new \DateTimeImmutable('2026-07-01');

        $repo->initializeTrip($tripId, $request);
        $repo->storeSourceType($tripId, SourceType::KOMOOT_TOUR->value);

        $stage0 = new Stage(
            tripId: $tripId,
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(45.0, 5.0),
            endPoint: new Coordinate(45.5, 5.5),
        );

        $accommodation = new Accommodation(
            name: 'Camping Les Pins',
            type: 'camp_site',
            lat: 45.48,
            lon: 5.48,
            estimatedPriceMin: 12.0,
            estimatedPriceMax: 18.0,
            isExactPrice: false,
        );
        $stage0->addAccommodation($accommodation);

        $stage1 = new Stage(
            tripId: $tripId,
            dayNumber: 2,
            distance: 70.0,
            elevation: 400.0,
            startPoint: new Coordinate(45.5, 5.5),
            endPoint: new Coordinate(46.0, 6.0),
        );

        $repo->storeStages($tripId, [$stage0, $stage1]);

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
    public function selectAccommodationUpdatesEndPointAndNextStartPoint(): void
    {
        self::createClient();
        $this->seedTripWithStages(self::TRIP_ID);

        $response = self::createClient()->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0/accommodation', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'selectedAccommodationLat' => 45.48,
                'selectedAccommodationLon' => 5.48,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');

        $data = $response->toArray(false);
        $this->assertSame('StageResponse', $data['@type']);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);

        $this->assertNotNull($stages);
        $this->assertCount(2, $stages);

        // Stage 0 endPoint updated to accommodation coordinates
        $this->assertEqualsWithDelta(45.48, $stages[0]->endPoint->lat, 0.001);
        $this->assertEqualsWithDelta(5.48, $stages[0]->endPoint->lon, 0.001);

        // Stage 1 startPoint updated to accommodation coordinates
        $this->assertEqualsWithDelta(45.48, $stages[1]->startPoint->lat, 0.001);
        $this->assertEqualsWithDelta(5.48, $stages[1]->startPoint->lon, 0.001);

        // Selected accommodation is set
        $this->assertNotNull($stages[0]->selectedAccommodation);
        $this->assertSame('Camping Les Pins', $stages[0]->selectedAccommodation->name);

        // Only the selected accommodation remains
        $this->assertCount(1, $stages[0]->accommodations);
    }

    #[Test]
    public function selectAccommodationDispatchesRecalculate(): void
    {
        self::createClient();
        $this->seedTripWithStages(self::TRIP_ID);

        self::createClient()->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0/accommodation', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'selectedAccommodationLat' => 45.48,
                'selectedAccommodationLon' => 5.48,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messageClasses = array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()::class,
            $transport->getSent(),
        );

        $this->assertContains(RecalculateStages::class, $messageClasses);
        $this->assertContains(FetchWeather::class, $messageClasses);
        $this->assertContains(CheckCalendar::class, $messageClasses);
    }

    private function seedTripWithSelectedAccommodation(string $tripId): void
    {
        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/987654321';
        $request->startDate = new \DateTimeImmutable('2026-07-01');

        $repo->initializeTrip($tripId, $request);
        $repo->storeSourceType($tripId, SourceType::KOMOOT_TOUR->value);

        $accommodation = new Accommodation(
            name: 'Camping Les Pins',
            type: 'camp_site',
            lat: 45.48,
            lon: 5.48,
            estimatedPriceMin: 12.0,
            estimatedPriceMax: 18.0,
            isExactPrice: false,
        );

        $stage0 = new Stage(
            tripId: $tripId,
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(45.0, 5.0),
            endPoint: new Coordinate(45.48, 5.48),
        );
        $stage0->accommodations = [$accommodation];
        $stage0->selectedAccommodation = $accommodation;

        $stage1 = new Stage(
            tripId: $tripId,
            dayNumber: 2,
            distance: 70.0,
            elevation: 400.0,
            startPoint: new Coordinate(45.48, 5.48),
            endPoint: new Coordinate(46.0, 6.0),
        );

        $repo->storeStages($tripId, [$stage0, $stage1]);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = $container->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations($tripId, ComputationName::cases());
    }

    #[Test]
    public function deselectAccommodationDispatchesScanAccommodations(): void
    {
        self::createClient();
        $this->seedTripWithSelectedAccommodation(self::TRIP_ID);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        self::createClient()->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0/accommodation', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['selectedAccommodationLat' => null, 'selectedAccommodationLon' => null],
        ]);
        $this->assertResponseStatusCodeSame(202);

        $messageClasses = array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()::class,
            $transport->getSent(),
        );

        $this->assertContains(ScanAccommodations::class, $messageClasses);
        $this->assertContains(RecalculateStages::class, $messageClasses);

        $recalculateMessages = array_filter(
            $transport->getSent(),
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof RecalculateStages,
        );
        $this->assertCount(1, $recalculateMessages);
        /** @var RecalculateStages $recalculateMessage */
        $recalculateMessage = array_first($recalculateMessages)->getMessage();
        $this->assertSame([0, 1], $recalculateMessage->affectedIndices);
    }

    private function seedTripWithSelectedAccommodationOnly(string $tripId): void
    {
        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/987654321';
        $request->startDate = new \DateTimeImmutable('2026-07-01');

        $repo->initializeTrip($tripId, $request);
        $repo->storeSourceType($tripId, SourceType::KOMOOT_TOUR->value);

        $accommodation = new Accommodation(
            name: 'Camping Les Pins',
            type: 'camp_site',
            lat: 45.48,
            lon: 5.48,
            estimatedPriceMin: 12.0,
            estimatedPriceMax: 18.0,
            isExactPrice: false,
        );

        $stage0 = new Stage(
            tripId: $tripId,
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(45.0, 5.0),
            endPoint: new Coordinate(45.48, 5.48),
        );
        // accommodations list is intentionally empty (simulates a concurrent re-scan that cleared it)
        $stage0->accommodations = [];
        $stage0->selectedAccommodation = $accommodation;

        $stage1 = new Stage(
            tripId: $tripId,
            dayNumber: 2,
            distance: 70.0,
            elevation: 400.0,
            startPoint: new Coordinate(45.48, 5.48),
            endPoint: new Coordinate(46.0, 6.0),
        );

        $repo->storeStages($tripId, [$stage0, $stage1]);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = $container->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations($tripId, ComputationName::cases());
    }

    #[Test]
    public function selectAccommodationWithStaleCoordinatesReturns409(): void
    {
        self::createClient();
        $this->seedTripWithStages(self::TRIP_ID);

        self::createClient()->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0/accommodation', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'selectedAccommodationLat' => 45.0,
                'selectedAccommodationLon' => 5.0,
            ],
        ]);

        $this->assertResponseStatusCodeSame(409);
    }

    #[Test]
    public function selectAccommodationFallsBackToSelectedAccommodationReturns202(): void
    {
        self::createClient();
        $this->seedTripWithSelectedAccommodationOnly(self::TRIP_ID);

        self::createClient()->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/0/accommodation', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'selectedAccommodationLat' => 45.48,
                'selectedAccommodationLon' => 5.48,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
    }

    #[Test]
    public function selectAccommodationOnNonExistentStageReturns404(): void
    {
        self::createClient();
        $this->seedTripWithStages(self::TRIP_ID);

        self::createClient()->request('PATCH', '/trips/'.self::TRIP_ID.'/stages/99/accommodation', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'selectedAccommodationLat' => 45.48,
                'selectedAccommodationLon' => 5.48,
            ],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
