<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\CulturalPoiAlert;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\Trip;
use App\Enum\AlertType;
use App\Repository\DoctrineTripRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DoctrineTripRequestRepository::class)]
final class DoctrineTripRequestRepositoryTest extends TestCase
{
    private EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $entityManager;

    private CacheItemPoolInterface&\PHPUnit\Framework\MockObject\MockObject $cache;

    private DoctrineTripRequestRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->repository = new DoctrineTripRequestRepository($this->entityManager, $this->cache);
    }

    #[Test]
    public function initializeTripAndGetRequestRoundtrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = new \DateTimeImmutable('2026-07-01');
        $request->endDate = new \DateTimeImmutable('2026-07-10');
        $request->fatigueFactor = 0.85;
        $request->elevationPenalty = 40.0;
        $request->ebikeMode = true;
        $request->departureHour = 9;
        $request->maxDistancePerDay = 60.0;
        $request->averageSpeed = 18.0;
        $request->enabledAccommodationTypes = ['camp_site', 'hotel'];

        // Capture the persisted Trip entity
        $persistedTrip = null;
        $this->entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(function (Trip $trip) use (&$persistedTrip): void {
                $persistedTrip = $trip;
            });
        $this->entityManager->expects(self::once())
            ->method('flush');

        $this->repository->initializeTrip($tripId, $request);

        self::assertInstanceOf(Trip::class, $persistedTrip);

        // Now test getRequest by having find() return the persisted entity
        $em2 = $this->createMock(EntityManagerInterface::class);
        $em2->expects(self::any())->method('find')
            ->with(Trip::class, Uuid::fromString($tripId))
            ->willReturn($persistedTrip);

        $repo2 = new DoctrineTripRequestRepository($em2, $this->cache);
        $result = $repo2->getRequest($tripId);

        self::assertNotNull($result);
        self::assertSame('https://www.komoot.com/tour/123456789', $result->sourceUrl);
        self::assertSame('2026-07-01', $result->startDate?->format('Y-m-d'));
        self::assertSame('2026-07-10', $result->endDate?->format('Y-m-d'));
        self::assertSame(0.85, $result->fatigueFactor);
        self::assertSame(40.0, $result->elevationPenalty);
        self::assertTrue($result->ebikeMode);
        self::assertSame(9, $result->departureHour);
        self::assertSame(60.0, $result->maxDistancePerDay);
        self::assertSame(18.0, $result->averageSpeed);
        self::assertSame(['camp_site', 'hotel'], $result->enabledAccommodationTypes);
    }

    #[Test]
    public function storeAndGetStagesWithAllData(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $trip = new Trip(Uuid::fromString($tripId));

        $this->entityManager->expects(self::any())->method('find')
            ->with(Trip::class, Uuid::fromString($tripId))
            ->willReturn($trip);
        $this->entityManager->expects(self::exactly(2))
            ->method('flush');

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

        $alert = new Alert(
            type: AlertType::WARNING,
            message: 'Strong wind expected',
            lat: 48.0,
            lon: 3.5,
        );

        $poi = new PointOfInterest(
            name: 'Cathédrale de Sens',
            category: 'monument',
            lat: 48.197,
            lon: 3.283,
            distanceFromStart: 85.2,
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
        $stageDto->addAlert($alert);
        $stageDto->addPoi($poi);
        $stageDto->addAccommodation($accommodation);
        $stageDto->selectedAccommodation = $selectedAccommodation;

        $this->repository->storeStages($tripId, [$stageDto]);

        // Now retrieve stages
        $stages = $this->repository->getStages($tripId);

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

        // Alerts
        self::assertCount(1, $result->alerts);
        self::assertSame(AlertType::WARNING, $result->alerts[0]->type);
        self::assertSame('Strong wind expected', $result->alerts[0]->message);
        self::assertSame(48.0, $result->alerts[0]->lat);
        self::assertSame(3.5, $result->alerts[0]->lon);

        // POIs
        self::assertCount(1, $result->pois);
        self::assertSame('Cathédrale de Sens', $result->pois[0]->name);
        self::assertSame('monument', $result->pois[0]->category);
        self::assertSame(48.197, $result->pois[0]->lat);
        self::assertSame(3.283, $result->pois[0]->lon);
        self::assertSame(85.2, $result->pois[0]->distanceFromStart);

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
    public function getRequestReturnsNullForInvalidUuid(): void
    {
        $this->entityManager->expects(self::never())
            ->method('find');

        $result = $this->repository->getRequest('not-a-valid-uuid');

        self::assertNull($result);
    }

    #[Test]
    public function getRequestReturnsNullForNonExistentTrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $this->entityManager->expects(self::any())->method('find')
            ->with(Trip::class, Uuid::fromString($tripId))
            ->willReturn(null);

        $result = $this->repository->getRequest($tripId);

        self::assertNull($result);
    }

    #[Test]
    public function culturalPoiAlertRoundtrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $trip = new Trip(Uuid::fromString($tripId));

        $this->entityManager->expects(self::any())->method('find')
            ->with(Trip::class, Uuid::fromString($tripId))
            ->willReturn($trip);
        $this->entityManager->expects(self::exactly(2))
            ->method('flush');

        $culturalAlert = new CulturalPoiAlert(
            type: AlertType::NUDGE,
            message: 'Nearby: Château de Fontainebleau',
            lat: 48.4,
            lon: 2.7,
            poiName: 'Château de Fontainebleau',
            poiType: 'castle',
            poiLat: 48.4010,
            poiLon: 2.7004,
            distanceFromRoute: 350,
        );

        $stageDto = new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 85.2,
            elevation: 920.0,
            startPoint: new Coordinate(48.8566, 2.3522, 35.0),
            endPoint: new Coordinate(47.9983, 3.5736, 180.0),
        );
        $stageDto->addAlert($culturalAlert);

        $this->repository->storeStages($tripId, [$stageDto]);

        $stages = $this->repository->getStages($tripId);

        self::assertNotNull($stages);
        self::assertCount(1, $stages);
        self::assertCount(1, $stages[0]->alerts);

        $resultAlert = $stages[0]->alerts[0];
        self::assertInstanceOf(CulturalPoiAlert::class, $resultAlert);
        self::assertSame(AlertType::NUDGE, $resultAlert->type);
        self::assertSame('Nearby: Château de Fontainebleau', $resultAlert->message);
        self::assertSame(48.4, $resultAlert->lat);
        self::assertSame(2.7, $resultAlert->lon);
        self::assertSame('Château de Fontainebleau', $resultAlert->poiName);
        self::assertSame('castle', $resultAlert->poiType);
        self::assertSame(48.4010, $resultAlert->poiLat);
        self::assertSame(2.7004, $resultAlert->poiLon);
        self::assertSame(350, $resultAlert->distanceFromRoute);
    }

    #[Test]
    public function rawPointsUsesCache(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $cacheKey = sprintf('trip.%s.raw_points', $tripId);
        $rawPoints = [
            ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
            ['lat' => 47.9983, 'lon' => 3.5736, 'ele' => 180.0],
        ];

        // Store: mock cache item for save
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects(self::once())
            ->method('set')
            ->with($rawPoints);
        $cacheItem->expects(self::once())
            ->method('expiresAfter')
            ->with(1800);

        $this->cache->expects(self::once())
            ->method('getItem')
            ->with($cacheKey)
            ->willReturn($cacheItem);
        $this->cache->expects(self::once())
            ->method('save')
            ->with($cacheItem);

        // EntityManager should NOT be called for raw points
        $this->entityManager->expects(self::never())
            ->method('find');
        $this->entityManager->expects(self::never())
            ->method('persist');
        $this->entityManager->expects(self::never())
            ->method('flush');

        $this->repository->storeRawPoints($tripId, $rawPoints);
    }

    #[Test]
    public function getRawPointsReturnsCachedData(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $cacheKey = sprintf('trip.%s.raw_points', $tripId);
        $rawPoints = [
            ['lat' => 48.8566, 'lon' => 2.3522, 'ele' => 35.0],
        ];

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($rawPoints);
        $cacheItem->expects(self::once())
            ->method('expiresAfter')
            ->with(1800);

        $this->cache->expects(self::once())
            ->method('getItem')
            ->with($cacheKey)
            ->willReturn($cacheItem);
        $this->cache->expects(self::once())
            ->method('save')
            ->with($cacheItem);

        $result = $this->repository->getRawPoints($tripId);

        self::assertSame($rawPoints, $result);
    }

    #[Test]
    public function getRawPointsReturnsNullOnCacheMiss(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $cacheKey = sprintf('trip.%s.raw_points', $tripId);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cache->expects(self::once())
            ->method('getItem')
            ->with($cacheKey)
            ->willReturn($cacheItem);

        $result = $this->repository->getRawPoints($tripId);

        self::assertNull($result);
    }

    #[Test]
    public function storeTitleUpdatesEntity(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $trip = new Trip(Uuid::fromString($tripId));

        $this->entityManager->expects(self::any())->method('find')
            ->with(Trip::class, Uuid::fromString($tripId))
            ->willReturn($trip);
        $this->entityManager->expects(self::once())
            ->method('flush');

        $this->repository->storeTitle($tripId, 'Mon voyage');

        self::assertSame('Mon voyage', $trip->getTitle());
    }

    #[Test]
    public function storeRequestUpdatesExistingTrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $trip = new Trip(Uuid::fromString($tripId));
        $trip->setSourceUrl('https://www.komoot.com/tour/111');

        $this->entityManager->expects(self::any())->method('find')
            ->with(Trip::class, Uuid::fromString($tripId))
            ->willReturn($trip);
        $this->entityManager->expects(self::once())
            ->method('flush');

        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/222';
        $request->fatigueFactor = 0.8;

        $this->repository->storeRequest($tripId, $request);

        self::assertSame('https://www.komoot.com/tour/222', $trip->getSourceUrl());
        self::assertSame(0.8, $trip->getFatigueFactor());
    }

    #[Test]
    public function getStagesReturnsNullForNonExistentTrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $this->entityManager->expects(self::any())->method('find')
            ->with(Trip::class, Uuid::fromString($tripId))
            ->willReturn(null);

        $result = $this->repository->getStages($tripId);

        self::assertNull($result);
    }

    #[Test]
    public function getStagesReturnsNullForTripWithNoStages(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $trip = new Trip(Uuid::fromString($tripId));

        $this->entityManager->expects(self::any())->method('find')
            ->with(Trip::class, Uuid::fromString($tripId))
            ->willReturn($trip);

        $result = $this->repository->getStages($tripId);

        self::assertNull($result);
    }

    #[Test]
    public function storeSourceTypeAndLocale(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $trip = new Trip(Uuid::fromString($tripId));

        $this->entityManager->expects(self::any())->method('find')
            ->with(Trip::class, Uuid::fromString($tripId))
            ->willReturn($trip);
        $this->entityManager->expects(self::exactly(2))
            ->method('flush');

        $this->repository->storeSourceType($tripId, 'komoot');
        self::assertSame('komoot', $trip->getSourceType());

        $this->repository->storeLocale($tripId, 'fr');
        self::assertSame('fr', $trip->getLocale());
    }

    #[Test]
    public function storeStagesIgnoresNonExistentTrip(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        $this->entityManager->expects(self::any())->method('find')
            ->with(Trip::class, Uuid::fromString($tripId))
            ->willReturn(null);
        $this->entityManager->expects(self::never())
            ->method('flush');

        $this->repository->storeStages($tripId, []);
    }
}
