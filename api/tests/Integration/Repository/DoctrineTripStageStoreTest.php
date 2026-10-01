<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use PHPUnit\Framework\MockObject\MockObject;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Enum\AlertGroup;
use App\Osm\CoverageRepositoryInterface;
use App\Osm\CycleRouteRepositoryInterface;
use App\Mapper\EventArrayMapper;
use App\Mapper\StageArrayMapper;
use App\Weather\WeatherForecastSerializer;
use App\Entity\Stage as StageEntity;
use App\Repository\DoctrineTripRequestRepository;
use App\Repository\DoctrineTripStageStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Symfony\Component\Uid\Uuid;

/**
 * Round trips through a real database: the JSONB columns, the reconciling re-read and the
 * flush are the database's, not a harness answering from the in-memory collection.
 */
#[CoversClass(DoctrineTripStageStore::class)]
#[AllowMockObjectsWithoutExpectations]
#[ResetDatabase]
final class DoctrineTripStageStoreTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private DoctrineTripRequestRepository $trips;

    private DoctrineTripStageStore $store;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        /** @var DoctrineTripRequestRepository $trips */
        $trips = $container->get(DoctrineTripRequestRepository::class);
        $this->trips = $trips;

        // The PostGIS metrics are computed at storeStages() time (#775); stub them
        // with neutral defaults so the persistence round-trips stay deterministic.
        $cycleRouteRepository = $this->createMock(CycleRouteRepositoryInterface::class);
        $cycleRouteRepository->method('onNetworkFractions')->willReturn([]);
        $coverageRepository = $this->createMock(CoverageRepositoryInterface::class);
        $coverageRepository->method('isRouteOutOfZone')->willReturn(false);

        $this->store = $this->storeWithOsm($cycleRouteRepository, $coverageRepository);
    }

    #[Test]
    public function storeAndGetStagesWithAllData(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->trip($tripId);

        $weather = new WeatherForecast(
            icon: 'sun',
            description: 'Sunny',
            tempMin: 15.0,
            tempMax: 28.0,
            windSpeed: 12.5,
            windDirection: 'NW',
            precipitationProbability: 10,
            humidity: 55,
            comfortIndex: 8,
            relativeWindDirection: WeatherForecast::RELATIVE_WIND_TAILWIND,
        );

        $poi = new PointOfInterest(
            name: 'Cathédrale de Sens',
            category: 'monument',
            lat: 48.197,
            lon: 3.283,
            distanceFromStart: 85.2,
            osmType: 'way',
            osmId: 4242,
        );

        $accommodation = new Accommodation(
            name: 'Camping du Parc',
            type: 'camp_site',
            lat: 47.998,
            lon: 3.574,
            estimatedPriceMin: 12.0,
            estimatedPriceMax: 18.0,
            isExactPrice: false,
            url: 'https://example.com/camping',
            possibleClosed: false,
            distanceToEndPoint: 0.5,
        );

        $selectedAccommodation = new Accommodation(
            name: 'Hôtel Central',
            type: 'hotel',
            lat: 47.322,
            lon: 5.042,
            estimatedPriceMin: 65.0,
            estimatedPriceMax: 95.0,
            isExactPrice: true,
            url: 'https://example.com/hotel',
            possibleClosed: false,
            distanceToEndPoint: 0.2,
        );

        $stageDto = new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 85.2,
            elevation: 920.0,
            startPoint: new Coordinate(48.8566, 2.3522, 35.0),
            endPoint: new Coordinate(47.9983, 3.5736, 180.0),
            geometry: [
                new Coordinate(48.8566, 2.3522, 35.0),
                new Coordinate(48.0, 3.5, 150.0),
                new Coordinate(47.9983, 3.5736, 180.0),
            ],
            label: 'Paris → Sens',
            elevationLoss: 780.0,
            isRestDay: false,
        );
        $stageDto->weather = $weather;
        $stageDto->setAlertsForGroup(AlertGroup::WIND, [
            ['code' => 'wind_headwind', 'type' => 'warning', 'message' => 'Strong wind expected'],
        ]);
        $stageDto->resupply = new Resupply(foodAtLunch: [$poi]);
        $stageDto->addAccommodation($accommodation);
        $stageDto->selectedAccommodation = $selectedAccommodation;

        $this->store->storeStages($tripId, [$stageDto]);

        // Now retrieve stages
        $stages = $this->store->getStages($tripId);

        self::assertNotNull($stages);
        self::assertCount(1, $stages);

        $result = $stages[0];
        self::assertSame($tripId, $result->tripId);
        self::assertSame(1, $result->dayNumber);
        self::assertSame(85.2, $result->distance);
        self::assertSame(920.0, $result->elevation);
        self::assertSame(780.0, $result->elevationLoss);
        self::assertSame('Paris → Sens', $result->label);
        self::assertFalse($result->isRestDay);

        // Coordinates
        self::assertSame(48.8566, $result->startPoint->lat);
        self::assertSame(2.3522, $result->startPoint->lon);
        self::assertSame(35.0, $result->startPoint->ele);
        self::assertSame(47.9983, $result->endPoint->lat);
        self::assertSame(3.5736, $result->endPoint->lon);
        self::assertSame(180.0, $result->endPoint->ele);

        // Geometry
        self::assertCount(3, $result->geometry);
        self::assertSame(48.8566, $result->geometry[0]->lat);
        self::assertSame(2.3522, $result->geometry[0]->lon);
        self::assertSame(35.0, $result->geometry[0]->ele);

        // Weather
        self::assertNotNull($result->weather);
        self::assertSame('sun', $result->weather->icon);
        self::assertSame('Sunny', $result->weather->description);
        self::assertSame(15.0, $result->weather->tempMin);
        self::assertSame(28.0, $result->weather->tempMax);
        self::assertSame(12.5, $result->weather->windSpeed);
        self::assertSame('NW', $result->weather->windDirection);
        self::assertSame(10, $result->weather->precipitationProbability);
        self::assertSame(55, $result->weather->humidity);
        self::assertSame(8, $result->weather->comfortIndex);
        self::assertSame(WeatherForecast::RELATIVE_WIND_TAILWIND, $result->weather->relativeWindDirection);

        // Alerts do NOT survive this round-trip, on purpose: storeStages() writes the
        // structure and the enrichment columns belong to the producers that compute them
        // (ADR-068). Carrying them back from the DTO is how a structural edit used to
        // replay a stale snapshot over a worker's write.
        self::assertSame([], $result->alerts);

        // Resupply (persisted in the repurposed pois column)
        self::assertNotNull($result->resupply);
        self::assertCount(1, $result->resupply->foodAtLunch);
        $lunchPoi = $result->resupply->foodAtLunch[0];
        self::assertSame('Cathédrale de Sens', $lunchPoi->name);
        self::assertSame('monument', $lunchPoi->category);
        self::assertSame(48.197, $lunchPoi->lat);
        self::assertSame(3.283, $lunchPoi->lon);
        self::assertSame(85.2, $lunchPoi->distanceFromStart);
        // Without these in poiToArray() the OSM link would vanish on reload and in
        // the shared view, exactly as the accommodation enrichment did (#870).
        self::assertSame('way', $lunchPoi->osmType);
        self::assertSame(4242, $lunchPoi->osmId);

        // Accommodations
        self::assertCount(1, $result->accommodations);
        self::assertSame('Camping du Parc', $result->accommodations[0]->name);
        self::assertSame('camp_site', $result->accommodations[0]->type);
        self::assertSame(12.0, $result->accommodations[0]->estimatedPriceMin);
        self::assertSame(18.0, $result->accommodations[0]->estimatedPriceMax);
        self::assertFalse($result->accommodations[0]->isExactPrice);
        self::assertSame('https://example.com/camping', $result->accommodations[0]->url);
        self::assertFalse($result->accommodations[0]->possibleClosed);
        self::assertSame(0.5, $result->accommodations[0]->distanceToEndPoint);

        // Selected accommodation
        self::assertNotNull($result->selectedAccommodation);
        self::assertSame('Hôtel Central', $result->selectedAccommodation->name);
        self::assertSame('hotel', $result->selectedAccommodation->type);
        self::assertSame(65.0, $result->selectedAccommodation->estimatedPriceMin);
        self::assertSame(95.0, $result->selectedAccommodation->estimatedPriceMax);
        self::assertTrue($result->selectedAccommodation->isExactPrice);
    }

    #[Test]
    public function legacyFlatPoiListReadsBackAsEmptyResupply(): void
    {
        // A pre-#1099 row: the (repurposed) pois column still holds the raw flat POI
        // list, without the foodAtLunch/foodAtArrival resupply keys. It must round-
        // trip to an empty Resupply, not throw or return garbage, until re-scanned.
        $tripId = Uuid::v7()->toRfc4122();
        $trip = $this->trip($tripId);

        $stageDto = new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 50.0,
            elevation: 100.0,
            startPoint: new Coordinate(48.0, 2.0, 0.0),
            endPoint: new Coordinate(48.5, 2.5, 0.0),
        );
        $this->store->storeStages($tripId, [$stageDto]);

        $stageEntity = $this->entityManager->getRepository(StageEntity::class)->findOneBy(['trip' => $trip]);
        self::assertInstanceOf(StageEntity::class, $stageEntity);
        $stageEntity->setPois([
            ['name' => 'Old shop', 'category' => 'bakery', 'lat' => 1.0, 'lon' => 2.0],
        ]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stages = $this->store->getStages($tripId);

        self::assertNotNull($stages);
        self::assertNotNull($stages[0]->resupply);
        self::assertTrue($stages[0]->resupply->isEmpty());
    }

    #[Test]
    public function accommodationEnrichmentSurvivesRoundtrip(): void
    {
        // #870: the five enrichment fields (source + Wikidata payload) were dropped
        // at write time, so a reload downgraded every card to a bare OSM entry.
        $tripId = Uuid::v7()->toRfc4122();
        $this->trip($tripId);

        $enriched = new Accommodation(
            name: 'Gîte du Morvan',
            type: 'guest_house',
            lat: 47.212,
            lon: 3.951,
            estimatedPriceMin: 55.0,
            estimatedPriceMax: 80.0,
            isExactPrice: true,
            url: 'https://example.com/gite',
            possibleClosed: true,
            distanceToEndPoint: 1.4,
            source: 'datatourisme',
            description: 'Maison de maître du XIXe siècle.',
            imageUrl: 'https://commons.example.org/gite.jpg',
            wikipediaUrl: 'https://fr.wikipedia.org/wiki/Gîte',
            openingHours: 'Mo-Su 08:00-20:00',
        );

        $stageDto = new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 60.0,
            elevation: 500.0,
            startPoint: new Coordinate(47.0, 3.8, 0.0),
            endPoint: new Coordinate(47.2, 3.95, 0.0),
        );
        $stageDto->addAccommodation($enriched);
        $stageDto->selectedAccommodation = $enriched;

        $this->store->storeStages($tripId, [$stageDto]);

        $stages = $this->store->getStages($tripId);

        self::assertNotNull($stages);

        foreach ([$stages[0]->accommodations[0], $stages[0]->selectedAccommodation] as $result) {
            self::assertNotNull($result);
            self::assertSame('datatourisme', $result->source);
            self::assertSame('Maison de maître du XIXe siècle.', $result->description);
            self::assertSame('https://commons.example.org/gite.jpg', $result->imageUrl);
            self::assertSame('https://fr.wikipedia.org/wiki/Gîte', $result->wikipediaUrl);
            self::assertSame('Mo-Su 08:00-20:00', $result->openingHours);
            // The ten pre-existing fields keep round-tripping unchanged.
            self::assertSame('Gîte du Morvan', $result->name);
            self::assertSame('guest_house', $result->type);
            self::assertSame('https://example.com/gite', $result->url);
            self::assertTrue($result->possibleClosed);
            self::assertSame(1.4, $result->distanceToEndPoint);
        }
    }

    #[Test]
    public function accommodationContactBlockAndOsmIdentitySurviveARoundTrip(): void
    {
        // #873: exactly the trap #870 documented — omitting the three new keys from
        // accommodationToArray() drops the tel: link and the "see on OSM" link on
        // every reload and in the anonymous shared view, while the live SSE shows them.
        $tripId = Uuid::v7()->toRfc4122();
        $this->trip($tripId);

        $osmEntry = new Accommodation(
            name: 'Camping du Pont',
            type: 'camp_site',
            lat: 43.947,
            lon: 4.535,
            estimatedPriceMin: 18.0,
            estimatedPriceMax: 18.0,
            isExactPrice: true,
            phone: '+33 4 66 37 82 00',
            address: '1 chemin du Pont, 30000 Nîmes',
            osmType: 'way',
            osmId: 987654321,
        );

        $stageDto = new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 60.0,
            elevation: 500.0,
            startPoint: new Coordinate(43.9, 4.5, 0.0),
            endPoint: new Coordinate(43.95, 4.54, 0.0),
        );
        $stageDto->addAccommodation($osmEntry);
        $stageDto->selectedAccommodation = $osmEntry;

        $this->store->storeStages($tripId, [$stageDto]);

        $stages = $this->store->getStages($tripId);

        self::assertNotNull($stages);

        foreach ([$stages[0]->accommodations[0], $stages[0]->selectedAccommodation] as $result) {
            self::assertNotNull($result);
            self::assertSame('+33 4 66 37 82 00', $result->phone);
            self::assertSame('1 chemin du Pont, 30000 Nîmes', $result->address);
            self::assertSame('way', $result->osmType);
            self::assertSame(987654321, $result->osmId);
        }
    }

    #[Test]
    public function accommodationPersistedWithoutEnrichmentKeysFallsBackToDefaults(): void
    {
        // Accommodations persisted before #870 carry only the ten legacy keys; they
        // must rehydrate on the constructor defaults instead of raising.
        $tripId = Uuid::v7()->toRfc4122();
        $trip = $this->trip($tripId);

        $legacy = [
            'name' => 'Camping Les Oliviers',
            'type' => 'camp_site',
            'lat' => 47.0,
            'lon' => 3.0,
            'estimatedPriceMin' => 12.0,
            'estimatedPriceMax' => 18.0,
            'isExactPrice' => false,
            'url' => 'https://example.com/oliviers',
            'possibleClosed' => false,
            'distanceToEndPoint' => 0.8,
        ];

        $stageEntity = new StageEntity($trip);
        $stageEntity->setPosition(0);
        $stageEntity->setDayNumber(1);
        $stageEntity->setDistance(10.0);
        $stageEntity->setElevation(100.0);
        $stageEntity->setStartLat(48.0);
        $stageEntity->setStartLon(2.0);
        $stageEntity->setEndLat(48.1);
        $stageEntity->setEndLon(2.1);
        $stageEntity->setAccommodations([$legacy]);
        $stageEntity->setSelectedAccommodation($legacy);

        $trip->addStage($stageEntity);
        $this->entityManager->persist($stageEntity);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stages = $this->store->getStages($tripId);

        self::assertNotNull($stages);

        foreach ([$stages[0]->accommodations[0], $stages[0]->selectedAccommodation] as $result) {
            self::assertNotNull($result);
            self::assertSame('Camping Les Oliviers', $result->name);
            self::assertSame('osm', $result->source);
            self::assertNull($result->description);
            self::assertNull($result->imageUrl);
            self::assertNull($result->wikipediaUrl);
            self::assertNull($result->openingHours);
            self::assertNull($result->phone);
            self::assertNull($result->osmType);
            self::assertNull($result->osmId);
        }
    }

    #[Test]
    public function getStagesReturnsNullForNonExistentTrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $result = $this->store->getStages($tripId);

        self::assertNull($result);
    }

    #[Test]
    public function getStagesReturnsEmptyArrayForTripWithNoStages(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->trip($tripId);

        $result = $this->store->getStages($tripId);

        self::assertSame([], $result);
    }

    #[Test]
    public function storeStagesIgnoresNonExistentTrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $this->store->storeStages($tripId, []);

        self::assertNull($this->store->getStages($tripId));
    }

    #[Test]
    public function storeStagesSkipsPostGisScansWhenGeometryIsUnchanged(): void
    {
        // #787: the heavy PostGIS metrics are geometry-derived, so a second store
        // of the identical route (an enrichment/edit pass that leaves geometry
        // untouched) must reuse the persisted values instead of re-scanning.
        $tripId = Uuid::v7()->toRfc4122();

        $cycleRoute = $this->createMock(CycleRouteRepositoryInterface::class);
        $cycleRoute->expects(self::once())->method('onNetworkFractions')->willReturn([0.42]);
        $coverage = $this->createMock(CoverageRepositoryInterface::class);
        $coverage->expects(self::once())->method('isRouteOutOfZone')->willReturn(false);

        $this->trip($tripId);
        $store = $this->storeWithOsm($cycleRoute, $coverage);

        $stage = $this->stageWithGeometry($tripId);
        $store->storeStages($tripId, [$stage]);
        // Re-storing the same stage with the same geometry must not trigger another scan.
        $store->storeStages($tripId, [$stage]);

        // The persisted fraction is preserved across the guarded second store.
        $stages = $store->getStages($tripId);
        self::assertNotNull($stages);
        self::assertEqualsWithDelta(0.42, $stages[0]->onCycleNetwork, 0.0001);
    }

    #[Test]
    public function storeStagesRecomputesPostGisScansWhenGeometryChanges(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $cycleRoute = $this->createMock(CycleRouteRepositoryInterface::class);
        $cycleRoute->expects(self::exactly(2))->method('onNetworkFractions')->willReturn([0.1]);
        $coverage = $this->createMock(CoverageRepositoryInterface::class);
        $coverage->expects(self::exactly(2))->method('isRouteOutOfZone')->willReturn(false);

        $this->trip($tripId);
        $store = $this->storeWithOsm($cycleRoute, $coverage);

        $stage = $this->stageWithGeometry($tripId);
        $store->storeStages($tripId, [$stage]);
        // Same stage, moved endpoint: the geometry signature changes → recompute.
        $stage->endPoint = new Coordinate(49.0, 3.0, 0.0);

        $store->storeStages($tripId, [$stage]);
    }

    private function trip(string $tripId): TripRequest
    {
        $this->trips->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));
        $trip = $this->trips->getRequest($tripId);
        self::assertInstanceOf(TripRequest::class, $trip);

        return $trip;
    }

    /**
     * No integral coordinate: the geometry column is JSONB and reads `2.0` back as the int 2,
     * which the strict signature comparison would take for a moved route.
     */
    private function stageWithGeometry(string $tripId): StageDto
    {
        return new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 55.0,
            elevation: 100.0,
            startPoint: new Coordinate(48.1, 2.05, 0.0),
            endPoint: new Coordinate(48.9, 2.05, 0.0),
            geometry: [
                new Coordinate(48.1, 2.05, 0.0),
                new Coordinate(48.5, 2.05, 0.0),
                new Coordinate(48.9, 2.05, 0.0),
            ],
        );
    }

    private function storeWithOsm(
        CycleRouteRepositoryInterface&MockObject $cycleRoute,
        CoverageRepositoryInterface&MockObject $coverage,
    ): DoctrineTripStageStore {
        return new DoctrineTripStageStore($this->entityManager, $cycleRoute, $coverage, new StageArrayMapper(new WeatherForecastSerializer(), new EventArrayMapper()));
    }
}
