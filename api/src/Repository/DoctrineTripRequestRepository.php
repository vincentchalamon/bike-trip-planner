<?php

declare(strict_types=1);

namespace App\Repository;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\CulturalPoiAlert;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\Stage as StageEntity;
use App\Entity\Trip;
use App\Enum\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

#[AsAlias(TripRequestRepositoryInterface::class)]
final readonly class DoctrineTripRequestRepository implements TripRequestRepositoryInterface
{
    private const int CACHE_TTL = 1800; // 30 minutes for transient data

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
    ) {
    }

    public function initializeTrip(string $tripId, TripRequest $request): void
    {
        $trip = $this->findTrip($tripId);
        if (!$trip instanceof Trip) {
            $trip = new Trip(Uuid::fromString($tripId));
            $this->entityManager->persist($trip);
        }

        $this->applyRequestToTrip($trip, $request);
        $this->entityManager->flush();
    }

    public function getRequest(string $tripId): ?TripRequest
    {
        $trip = $this->findTrip($tripId);
        if (!$trip instanceof Trip) {
            return null;
        }

        return $this->tripToRequest($trip);
    }

    public function storeRequest(string $tripId, TripRequest $request): void
    {
        $trip = $this->findTrip($tripId);
        if (!$trip instanceof Trip) {
            return;
        }

        $this->applyRequestToTrip($trip, $request);
        $this->entityManager->flush();
    }

    public function getTitle(string $tripId): ?string
    {
        $trip = $this->findTrip($tripId);

        return $trip?->getTitle();
    }

    public function storeTitle(string $tripId, ?string $title): void
    {
        $trip = $this->findTrip($tripId);
        if (!$trip instanceof Trip) {
            return;
        }

        $trip->setTitle($title);
        $this->entityManager->flush();
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $rawPoints */
    public function storeRawPoints(string $tripId, array $rawPoints): void
    {
        $this->cacheSet(sprintf('trip.%s.raw_points', $tripId), $rawPoints);
    }

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getRawPoints(string $tripId): ?array
    {
        /** @var list<array{lat: float, lon: float, ele: float}>|null $value */
        $value = $this->cacheGet(sprintf('trip.%s.raw_points', $tripId));

        return $value;
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $decimatedPoints */
    public function storeDecimatedPoints(string $tripId, array $decimatedPoints): void
    {
        $this->cacheSet(sprintf('trip.%s.decimated_points', $tripId), $decimatedPoints);
    }

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getDecimatedPoints(string $tripId): ?array
    {
        /** @var list<array{lat: float, lon: float, ele: float}>|null $value */
        $value = $this->cacheGet(sprintf('trip.%s.decimated_points', $tripId));

        return $value;
    }

    /**
     * @param list<list<array{lat: float, lon: float, ele: float}>> $tracksData
     */
    public function storeTracksData(string $tripId, array $tracksData): void
    {
        $this->cacheSet(sprintf('trip.%s.tracks_data', $tripId), $tracksData);
    }

    /** @return list<list<array{lat: float, lon: float, ele: float}>>|null */
    public function getTracksData(string $tripId): ?array
    {
        /** @var list<list<array{lat: float, lon: float, ele: float}>>|null $value */
        $value = $this->cacheGet(sprintf('trip.%s.tracks_data', $tripId));

        return $value;
    }

    public function storeSourceType(string $tripId, string $sourceType): void
    {
        $trip = $this->findTrip($tripId);
        if (!$trip instanceof Trip) {
            return;
        }

        $trip->setSourceType($sourceType);
        $this->entityManager->flush();
    }

    public function getSourceType(string $tripId): ?string
    {
        $trip = $this->findTrip($tripId);

        return $trip?->getSourceType();
    }

    public function storeLocale(string $tripId, string $locale): void
    {
        $trip = $this->findTrip($tripId);
        if (!$trip instanceof Trip) {
            return;
        }

        $trip->setLocale($locale);
        $this->entityManager->flush();
    }

    public function getLocale(string $tripId): ?string
    {
        $trip = $this->findTrip($tripId);

        return $trip?->getLocale();
    }

    /** @param list<StageDto> $stages */
    public function storeStages(string $tripId, array $stages): void
    {
        $trip = $this->findTrip($tripId);
        if (!$trip instanceof Trip) {
            return;
        }

        $trip->clearStages();
        // Must flush to execute orphan removal before adding new stages
        $this->entityManager->flush();

        foreach ($stages as $index => $stageDto) {
            $stageEntity = $this->stageDtoToEntity($stageDto, $trip, $index);
            $trip->addStage($stageEntity);
        }

        $this->entityManager->flush();
    }

    /** @return list<StageDto>|null */
    public function getStages(string $tripId): ?array
    {
        $trip = $this->findTrip($tripId);
        if (!$trip instanceof Trip) {
            return null;
        }

        $stages = $trip->getStages();
        if ($stages->isEmpty()) {
            return null;
        }

        $result = [];
        foreach ($stages as $stageEntity) {
            $result[] = $this->stageEntityToDto($stageEntity);
        }

        return $result;
    }

    // --- Private helpers ---

    private function findTrip(string $tripId): ?Trip
    {
        if (!Uuid::isValid($tripId)) {
            return null;
        }

        return $this->entityManager->find(Trip::class, Uuid::fromString($tripId));
    }

    private function applyRequestToTrip(Trip $trip, TripRequest $request): void
    {
        $trip->setSourceUrl($request->sourceUrl);
        $trip->setStartDate($request->startDate);
        $trip->setEndDate($request->endDate);
        $trip->setFatigueFactor($request->fatigueFactor);
        $trip->setElevationPenalty($request->elevationPenalty);
        $trip->setEbikeMode($request->ebikeMode);
        $trip->setDepartureHour($request->departureHour);
        $trip->setMaxDistancePerDay($request->maxDistancePerDay);
        $trip->setAverageSpeed($request->averageSpeed);
        $trip->setEnabledAccommodationTypes($request->enabledAccommodationTypes);
    }

    private function tripToRequest(Trip $trip): TripRequest
    {
        $request = new TripRequest();
        $request->sourceUrl = $trip->getSourceUrl();
        $request->startDate = $trip->getStartDate();
        $request->endDate = $trip->getEndDate();
        $request->fatigueFactor = $trip->getFatigueFactor();
        $request->elevationPenalty = $trip->getElevationPenalty();
        $request->ebikeMode = $trip->isEbikeMode();
        $request->departureHour = $trip->getDepartureHour();
        $request->maxDistancePerDay = $trip->getMaxDistancePerDay();
        $request->averageSpeed = $trip->getAverageSpeed();
        $request->enabledAccommodationTypes = $trip->getEnabledAccommodationTypes();

        return $request;
    }

    private function stageDtoToEntity(StageDto $dto, Trip $trip, int $position): StageEntity
    {
        $entity = new StageEntity($trip);
        $entity->setPosition($position);
        $entity->setDayNumber($dto->dayNumber);
        $entity->setDistance($dto->distance);
        $entity->setElevation($dto->elevation);
        $entity->setElevationLoss($dto->elevationLoss);
        $entity->setStartLat($dto->startPoint->lat);
        $entity->setStartLon($dto->startPoint->lon);
        $entity->setStartEle($dto->startPoint->ele);
        $entity->setEndLat($dto->endPoint->lat);
        $entity->setEndLon($dto->endPoint->lon);
        $entity->setEndEle($dto->endPoint->ele);
        $entity->setLabel($dto->label);
        $entity->setIsRestDay($dto->isRestDay);

        // Geometry: list<Coordinate> → list<array{lat, lon, ele}>
        $geometry = [];
        foreach ($dto->geometry as $coord) {
            $geometry[] = ['lat' => $coord->lat, 'lon' => $coord->lon, 'ele' => $coord->ele];
        }

        $entity->setGeometry($geometry);

        // Weather: WeatherForecast|null → array|null
        if ($dto->weather instanceof WeatherForecast) {
            $entity->setWeather($this->weatherToArray($dto->weather));
        }

        // Alerts: Alert[] → list<array>
        $alerts = [];
        foreach ($dto->alerts as $alert) {
            $alerts[] = $this->alertToArray($alert);
        }

        $entity->setAlerts($alerts);

        // POIs: PointOfInterest[] → list<array>
        $pois = [];
        foreach ($dto->pois as $poi) {
            $pois[] = $this->poiToArray($poi);
        }

        $entity->setPois($pois);

        // Accommodations: Accommodation[] → list<array>
        $accommodations = [];
        foreach ($dto->accommodations as $accommodation) {
            $accommodations[] = $this->accommodationToArray($accommodation);
        }

        $entity->setAccommodations($accommodations);

        // Selected accommodation
        if ($dto->selectedAccommodation instanceof Accommodation) {
            $entity->setSelectedAccommodation($this->accommodationToArray($dto->selectedAccommodation));
        }

        return $entity;
    }

    private function stageEntityToDto(StageEntity $entity): StageDto
    {
        $dto = new StageDto(
            tripId: $entity->getTrip()->getId()->toRfc4122(),
            dayNumber: $entity->getDayNumber(),
            distance: $entity->getDistance(),
            elevation: $entity->getElevation(),
            startPoint: new Coordinate($entity->getStartLat(), $entity->getStartLon(), $entity->getStartEle()),
            endPoint: new Coordinate($entity->getEndLat(), $entity->getEndLon(), $entity->getEndEle()),
            geometry: array_map(
                static fn (array $point): Coordinate => new Coordinate($point['lat'], $point['lon'], $point['ele']),
                $entity->getGeometry(),
            ),
            label: $entity->getLabel(),
            elevationLoss: $entity->getElevationLoss(),
            isRestDay: $entity->isRestDay(),
        );

        // Weather
        /** @var array{icon: string, description: string, tempMin: float, tempMax: float, windSpeed: float, windDirection: string, precipitationProbability: int, humidity: int, comfortIndex: int, relativeWindDirection: string}|null $weatherData */
        $weatherData = $entity->getWeather();
        if (null !== $weatherData) {
            $dto->weather = $this->arrayToWeather($weatherData);
        }

        // Alerts
        /** @var list<array{type: string, message: string, lat?: ?float, lon?: ?float, _class?: string, poiName?: string, poiType?: string, poiLat?: float, poiLon?: float, distanceFromRoute?: int}> $alertsData */
        $alertsData = $entity->getAlerts();
        foreach ($alertsData as $alertData) {
            $dto->addAlert($this->arrayToAlert($alertData));
        }

        // POIs
        /** @var list<array{name: string, category: string, lat: float, lon: float, distanceFromStart?: ?float}> $poisData */
        $poisData = $entity->getPois();
        foreach ($poisData as $poiData) {
            $dto->addPoi($this->arrayToPoi($poiData));
        }

        // Accommodations
        /** @var list<array{name: string, type: string, lat: float, lon: float, estimatedPriceMin: float, estimatedPriceMax: float, isExactPrice: bool, url?: ?string, possibleClosed?: bool, distanceToEndPoint?: float}> $accommodationsData */
        $accommodationsData = $entity->getAccommodations();
        foreach ($accommodationsData as $accData) {
            $dto->addAccommodation($this->arrayToAccommodation($accData));
        }

        // Selected accommodation
        /** @var array{name: string, type: string, lat: float, lon: float, estimatedPriceMin: float, estimatedPriceMax: float, isExactPrice: bool, url?: ?string, possibleClosed?: bool, distanceToEndPoint?: float}|null $selectedData */
        $selectedData = $entity->getSelectedAccommodation();
        if (null !== $selectedData) {
            $dto->selectedAccommodation = $this->arrayToAccommodation($selectedData);
        }

        return $dto;
    }

    // --- Serialization helpers for JSONB columns ---

    /** @return array<string, mixed> */
    private function weatherToArray(WeatherForecast $weather): array
    {
        return [
            'icon' => $weather->icon,
            'description' => $weather->description,
            'tempMin' => $weather->tempMin,
            'tempMax' => $weather->tempMax,
            'windSpeed' => $weather->windSpeed,
            'windDirection' => $weather->windDirection,
            'precipitationProbability' => $weather->precipitationProbability,
            'humidity' => $weather->humidity,
            'comfortIndex' => $weather->comfortIndex,
            'relativeWindDirection' => $weather->relativeWindDirection,
        ];
    }

    /** @param array{icon: string, description: string, tempMin: float, tempMax: float, windSpeed: float, windDirection: string, precipitationProbability: int, humidity: int, comfortIndex: int, relativeWindDirection: string} $data */
    private function arrayToWeather(array $data): WeatherForecast
    {
        return new WeatherForecast(
            icon: $data['icon'],
            description: $data['description'],
            tempMin: $data['tempMin'],
            tempMax: $data['tempMax'],
            windSpeed: $data['windSpeed'],
            windDirection: $data['windDirection'],
            precipitationProbability: $data['precipitationProbability'],
            humidity: $data['humidity'],
            comfortIndex: $data['comfortIndex'],
            relativeWindDirection: $data['relativeWindDirection'],
        );
    }

    /** @return array{type: string, message: string, lat: ?float, lon: ?float, _class?: string, poiName?: string, poiType?: string, poiLat?: float, poiLon?: float, distanceFromRoute?: int} */
    private function alertToArray(Alert $alert): array
    {
        $data = [
            'type' => $alert->type->value,
            'message' => $alert->message,
            'lat' => $alert->lat,
            'lon' => $alert->lon,
        ];

        if ($alert instanceof CulturalPoiAlert) {
            $data['_class'] = 'CulturalPoiAlert';
            $data['poiName'] = $alert->poiName;
            $data['poiType'] = $alert->poiType;
            $data['poiLat'] = $alert->poiLat;
            $data['poiLon'] = $alert->poiLon;
            $data['distanceFromRoute'] = $alert->distanceFromRoute;
        }

        return $data;
    }

    /** @param array{type: string, message: string, lat?: ?float, lon?: ?float, _class?: string, poiName?: string, poiType?: string, poiLat?: float, poiLon?: float, distanceFromRoute?: int} $data */
    private function arrayToAlert(array $data): Alert
    {
        $type = AlertType::from($data['type']);
        $message = $data['message'];
        $lat = $data['lat'] ?? null;
        $lon = $data['lon'] ?? null;

        if (($data['_class'] ?? null) === 'CulturalPoiAlert') {
            return new CulturalPoiAlert(
                type: $type,
                message: $message,
                lat: $lat,
                lon: $lon,
                poiName: $data['poiName'] ?? '',
                poiType: $data['poiType'] ?? '',
                poiLat: $data['poiLat'] ?? 0.0,
                poiLon: $data['poiLon'] ?? 0.0,
                distanceFromRoute: $data['distanceFromRoute'] ?? 0,
            );
        }

        return new Alert(
            type: $type,
            message: $message,
            lat: $lat,
            lon: $lon,
        );
    }

    /** @return array{name: string, category: string, lat: float, lon: float, distanceFromStart: ?float} */
    private function poiToArray(PointOfInterest $poi): array
    {
        return [
            'name' => $poi->name,
            'category' => $poi->category,
            'lat' => $poi->lat,
            'lon' => $poi->lon,
            'distanceFromStart' => $poi->distanceFromStart,
        ];
    }

    /** @param array{name: string, category: string, lat: float, lon: float, distanceFromStart?: ?float} $data */
    private function arrayToPoi(array $data): PointOfInterest
    {
        return new PointOfInterest(
            name: $data['name'],
            category: $data['category'],
            lat: $data['lat'],
            lon: $data['lon'],
            distanceFromStart: $data['distanceFromStart'] ?? null,
        );
    }

    /** @return array{name: string, type: string, lat: float, lon: float, estimatedPriceMin: float, estimatedPriceMax: float, isExactPrice: bool, url: ?string, possibleClosed: bool, distanceToEndPoint: float} */
    private function accommodationToArray(Accommodation $acc): array
    {
        return [
            'name' => $acc->name,
            'type' => $acc->type,
            'lat' => $acc->lat,
            'lon' => $acc->lon,
            'estimatedPriceMin' => $acc->estimatedPriceMin,
            'estimatedPriceMax' => $acc->estimatedPriceMax,
            'isExactPrice' => $acc->isExactPrice,
            'url' => $acc->url,
            'possibleClosed' => $acc->possibleClosed,
            'distanceToEndPoint' => $acc->distanceToEndPoint,
        ];
    }

    /** @param array{name: string, type: string, lat: float, lon: float, estimatedPriceMin: float, estimatedPriceMax: float, isExactPrice: bool, url?: ?string, possibleClosed?: bool, distanceToEndPoint?: float} $data */
    private function arrayToAccommodation(array $data): Accommodation
    {
        return new Accommodation(
            name: $data['name'],
            type: $data['type'],
            lat: $data['lat'],
            lon: $data['lon'],
            estimatedPriceMin: $data['estimatedPriceMin'],
            estimatedPriceMax: $data['estimatedPriceMax'],
            isExactPrice: $data['isExactPrice'],
            url: $data['url'] ?? null,
            possibleClosed: $data['possibleClosed'] ?? false,
            distanceToEndPoint: $data['distanceToEndPoint'] ?? 0.0,
        );
    }

    // --- Redis cache helpers for transient data ---

    private function cacheSet(string $key, mixed $value): void
    {
        $item = $this->tripStateCache->getItem($key);
        $item->set($value);
        $item->expiresAfter(self::CACHE_TTL);

        $this->tripStateCache->save($item);
    }

    private function cacheGet(string $key): mixed
    {
        $item = $this->tripStateCache->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        return $item->get();
    }
}
