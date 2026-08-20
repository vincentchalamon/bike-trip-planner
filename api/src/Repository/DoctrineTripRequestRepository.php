<?php

declare(strict_types=1);

namespace App\Repository;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\CulturalPoiAlert;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\Stage as StageEntity;
use App\Enum\AlertCode;
use App\Enum\AlertType;
use App\Osm\CoverageRepositoryInterface;
use App\Osm\CycleRouteRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TripRequest>
 */
#[AsAlias(TripRequestRepositoryInterface::class)]
final class DoctrineTripRequestRepository extends ServiceEntityRepository implements TripRequestRepositoryInterface, OwnedTripFinderInterface
{
    private const int CACHE_TTL = 1800; // 30 minutes for transient data

    /** Tolerance (m) between the stage line and a cycle route to count as "on network". */
    private const int CYCLE_NETWORK_TOLERANCE_METERS = 30;

    public function __construct(
        ManagerRegistry $registry,
        #[Autowire(service: 'cache.trip_state')]
        private readonly CacheItemPoolInterface $tripStateCache,
        private readonly CycleRouteRepositoryInterface $cycleRouteRepository,
        private readonly CoverageRepositoryInterface $coverageRepository,
    ) {
        parent::__construct($registry, TripRequest::class);
    }

    public function initializeTrip(string $tripId, TripRequest $request): void
    {
        $existing = $this->findTripRequest($tripId);
        if ($existing instanceof TripRequest) {
            $this->copyModifiableFields($existing, $request);
        } else {
            $request->id = Uuid::fromString($tripId);
            $this->getEntityManager()->persist($request);
        }

        $this->getEntityManager()->flush();
    }

    public function getRequest(string $tripId): ?TripRequest
    {
        return $this->findTripRequest($tripId);
    }

    public function storeRequest(string $tripId, TripRequest $request): void
    {
        $managed = $this->findTripRequest($tripId);
        if (!$managed instanceof TripRequest) {
            return;
        }

        $this->copyModifiableFields($managed, $request);
        $this->getEntityManager()->flush();
    }

    public function getTitle(string $tripId): ?string
    {
        return $this->findTripRequest($tripId)?->title;
    }

    public function storeTitle(string $tripId, ?string $title): void
    {
        $trip = $this->findTripRequest($tripId);
        if (!$trip instanceof TripRequest) {
            return;
        }

        $trip->title = $title;
        $this->getEntityManager()->flush();
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $rawPoints */
    public function storeRawPoints(string $tripId, array $rawPoints): void
    {
        $this->cacheSet(\sprintf('trip.%s.raw_points', $tripId), $rawPoints);
    }

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getRawPoints(string $tripId): ?array
    {
        /** @var list<array{lat: float, lon: float, ele: float}>|null $value */
        $value = $this->cacheGet(\sprintf('trip.%s.raw_points', $tripId));

        return $value;
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $decimatedPoints */
    public function storeDecimatedPoints(string $tripId, array $decimatedPoints): void
    {
        $this->cacheSet(\sprintf('trip.%s.decimated_points', $tripId), $decimatedPoints);
    }

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getDecimatedPoints(string $tripId): ?array
    {
        /** @var list<array{lat: float, lon: float, ele: float}>|null $value */
        $value = $this->cacheGet(\sprintf('trip.%s.decimated_points', $tripId));

        return $value;
    }

    /**
     * @param list<list<array{lat: float, lon: float, ele: float}>> $tracksData
     */
    public function storeTracksData(string $tripId, array $tracksData): void
    {
        $this->cacheSet(\sprintf('trip.%s.tracks_data', $tripId), $tracksData);
    }

    /** @return list<list<array{lat: float, lon: float, ele: float}>>|null */
    public function getTracksData(string $tripId): ?array
    {
        /** @var list<list<array{lat: float, lon: float, ele: float}>>|null $value */
        $value = $this->cacheGet(\sprintf('trip.%s.tracks_data', $tripId));

        return $value;
    }

    public function storeSourceType(string $tripId, string $sourceType): void
    {
        $trip = $this->findTripRequest($tripId);
        if (!$trip instanceof TripRequest) {
            return;
        }

        $trip->sourceType = $sourceType;
        $this->getEntityManager()->flush();
    }

    public function getSourceType(string $tripId): ?string
    {
        return $this->findTripRequest($tripId)?->sourceType;
    }

    public function storeStatus(string $tripId, string $status): void
    {
        $trip = $this->findTripRequest($tripId);
        if (!$trip instanceof TripRequest) {
            return;
        }

        $trip->status = $status;
        $this->getEntityManager()->flush();
    }

    public function storeLocale(string $tripId, string $locale): void
    {
        $trip = $this->findTripRequest($tripId);
        if (!$trip instanceof TripRequest) {
            return;
        }

        $trip->locale = $locale;
        $this->getEntityManager()->flush();
    }

    public function getLocale(string $tripId): ?string
    {
        return $this->findTripRequest($tripId)?->locale;
    }

    public function getOwnerId(string $tripId): ?string
    {
        return $this->findTripRequest($tripId)?->user?->getId()->toRfc4122();
    }

    /**
     * Owned trips whose date range covers the given day, for the weather-safety
     * batch (#1124). Bounded to a 60-day look-back so ancient trips are not
     * scanned; the caller checks a non-rest stage actually falls on that day.
     *
     * @return list<TripRequest>
     */
    public function findOwnedTripsCoveringDate(\DateTimeImmutable $date): array
    {
        $floor = $date->modify('-60 days');

        /** @var list<TripRequest> $trips */
        // Fetch-join the stages: stageOnDay() iterates t.stages per trip, so a lazy
        // OneToMany would fire one SELECT per trip (N+1) on a batch that runs twice
        // a day over a 60-day window.
        $trips = $this->createQueryBuilder('t')
            ->leftJoin('t.stages', 's')
            ->addSelect('s')
            ->andWhere('t.user IS NOT NULL')
            ->andWhere('t.startDate IS NOT NULL')
            ->andWhere('t.startDate <= :date')
            ->andWhere('t.startDate > :floor')
            ->setParameter('date', $date)
            ->setParameter('floor', $floor)
            ->getQuery()
            ->getResult();

        return $trips;
    }

    /** @param list<StageDto> $stages */
    public function storeStages(string $tripId, array $stages): void
    {
        $trip = $this->findTripRequest($tripId);
        if (!$trip instanceof TripRequest) {
            return;
        }

        // The on-cycle-network fraction and out-of-zone flag are derived purely
        // from the route geometry, so they only change when the geometry does
        // (initial compute, route recalculation). storeStages() also runs on
        // every enrichment/edit pass (weather, accommodation select, distance
        // edit), which leaves the geometry untouched — guard the two heavy PostGIS
        // scans behind a geometry-change check so frequent edits reuse the already
        // persisted values (issue #775, perf review on #787).
        [$cycleNetwork, $outOfZone] = $this->geometryUnchanged($trip, $stages)
            ? [$this->persistedCycleNetwork($trip), $trip->outOfZone]
            : $this->computeRouteMetrics($stages);

        $this->getEntityManager()->wrapInTransaction(function () use ($trip, $stages, $cycleNetwork, $outOfZone): void {
            // Bulk delete: O(1) vs O(N) orphan-removal DELETEs (1 SELECT + N DELETE)
            $this->getEntityManager()
                ->createQuery('DELETE FROM App\Entity\Stage s WHERE s.trip = :trip')
                ->setParameter('trip', $trip)
                ->execute();
            $trip->clearStages(); // Keep UoW in sync with the deleted rows

            // Mutate the managed entity inside the transaction so a flush failure
            // does not leave a stale out-of-zone flag on the in-memory entity
            // (correctness review on #787).
            $trip->outOfZone = $outOfZone;

            foreach ($stages as $index => $stageDto) {
                $stageEntity = $this->stageDtoToEntity($stageDto, $trip, $index);
                $stageEntity->setOnCycleNetwork($cycleNetwork[$index] ?? 0.0);
                $trip->addStage($stageEntity);
            }

            $this->getEntityManager()->flush();
        });
    }

    /**
     * Computes the geometry-derived trip-detail metrics: the per-stage on-cycle-network
     * fraction (index-aligned with $stages) and the out-of-zone flag.
     *
     * @param list<StageDto> $stages
     *
     * @return array{0: list<float>, 1: bool}
     */
    private function computeRouteMetrics(array $stages): array
    {
        $cycleNetwork = $this->cycleRouteRepository->onNetworkFractions(
            array_map(
                static fn (StageDto $stage): array => array_map(
                    static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon],
                    $stage->geometry,
                ),
                $stages,
            ),
            self::CYCLE_NETWORK_TOLERANCE_METERS,
        );

        $outOfZone = $this->coverageRepository->isRouteOutOfZone($this->stageRoutePoints($stages));

        return [$cycleNetwork, $outOfZone];
    }

    /**
     * Returns true when the incoming stage geometry (and endpoints) match what is
     * already persisted, so the geometry-derived PostGIS metrics can be reused.
     *
     * @param list<StageDto> $stages
     */
    private function geometryUnchanged(TripRequest $trip, array $stages): bool
    {
        $persisted = $trip->stages;
        if ($persisted->count() !== \count($stages)) {
            return false;
        }

        foreach ($persisted->getValues() as $index => $entity) {
            if ($this->stageGeometrySignature($stages[$index]) !== $this->entityGeometrySignature($entity)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<float> The persisted on-cycle-network fractions, index-aligned with the stages. */
    private function persistedCycleNetwork(TripRequest $trip): array
    {
        return array_map(
            static fn (StageEntity $entity): float => $entity->getOnCycleNetwork(),
            $trip->stages->getValues(),
        );
    }

    /** @return list<array{float, float}> Endpoints + geometry coordinates of an incoming stage DTO. */
    private function stageGeometrySignature(StageDto $stage): array
    {
        $signature = [
            [$stage->startPoint->lat, $stage->startPoint->lon],
            [$stage->endPoint->lat, $stage->endPoint->lon],
        ];
        foreach ($stage->geometry as $coord) {
            $signature[] = [$coord->lat, $coord->lon];
        }

        return $signature;
    }

    /** @return list<array{float, float}> Endpoints + geometry coordinates of a persisted stage entity. */
    private function entityGeometrySignature(StageEntity $entity): array
    {
        $signature = [
            [$entity->getStartLat(), $entity->getStartLon()],
            [$entity->getEndLat(), $entity->getEndLon()],
        ];
        foreach ($entity->getGeometry() as $coord) {
            $signature[] = [$coord['lat'], $coord['lon']];
        }

        return $signature;
    }

    /** @return list<StageDto>|null */
    public function getStages(string $tripId): ?array
    {
        $trip = $this->findTripRequest($tripId);
        if (!$trip instanceof TripRequest) {
            return null;
        }

        $result = [];
        foreach ($trip->stages as $stageEntity) {
            $result[] = $this->stageEntityToDto($stageEntity);
        }

        return $result;
    }

    /**
     * Scalar read of the single `geometry` JSONB column — no join, no hydration of the
     * stage collection (weather, POIs, accommodations…) that {@see self::getStages()}
     * would pull in.
     *
     * @return list<array{lat: float, lon: float}>|null
     */
    public function getStageGeometry(string $tripId, int $dayNumber): ?array
    {
        if (!Uuid::isValid($tripId)) {
            return null;
        }

        /** @var array{geometry: list<array{lat: float, lon: float, ele: float}>}|null $row */
        $row = $this->getEntityManager()->createQuery(
            'SELECT s.geometry FROM App\Entity\Stage s WHERE s.trip = :tripId AND s.dayNumber = :dayNumber',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('dayNumber', $dayNumber)
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);

        if (null === $row || [] === $row['geometry']) {
            return null;
        }

        return array_map(
            static fn (array $point): array => ['lat' => $point['lat'], 'lon' => $point['lon']],
            $row['geometry'],
        );
    }

    // Atomic per-stage UPDATE of one JSONB column, keyed by dayNumber: lets parallel
    // enrichment handlers persist only their own column instead of the whole-collection
    // read-modify-write of storeStages() (which let a slow handler overwrite a sibling's
    // freshly-written column — recette #649). One literal DQL per column (a dynamic,
    // sprintf-built query string is not validated by phpstan-doctrine and avoids any
    // column-name interpolation). The value is bound as a single 'jsonb' parameter —
    // without the explicit type Doctrine infers ArrayParameterType and expands the list
    // into $1, $2, … .

    public function updateStageWeather(string $tripId, int $dayNumber, ?WeatherForecast $weather): void
    {
        if (!Uuid::isValid($tripId)) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\Stage s SET s.weather = :value WHERE s.trip = :tripId AND s.dayNumber = :dayNumber',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('dayNumber', $dayNumber)
            ->setParameter('value', $weather instanceof WeatherForecast ? $this->weatherToArray($weather) : null, 'jsonb')
            ->execute();
    }

    /** @param list<Alert> $alerts */
    public function updateStageAlerts(string $tripId, int $dayNumber, array $alerts): void
    {
        if (!Uuid::isValid($tripId)) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\Stage s SET s.alerts = :value WHERE s.trip = :tripId AND s.dayNumber = :dayNumber',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('dayNumber', $dayNumber)
            ->setParameter('value', array_map($this->alertToArray(...), $alerts), 'jsonb')
            ->execute();
    }

    public function updateStageResupply(string $tripId, int $dayNumber, Resupply $resupply): void
    {
        if (!Uuid::isValid($tripId)) {
            return;
        }

        // Stored in the (JSONB) `pois` column — repurposed to hold the curated
        // resupply object since the raw corridor set is no longer persisted (#1099).
        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\Stage s SET s.pois = :value WHERE s.trip = :tripId AND s.dayNumber = :dayNumber',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('dayNumber', $dayNumber)
            ->setParameter('value', $this->resupplyToArray($resupply), 'jsonb')
            ->execute();
    }

    /** @param list<Accommodation> $accommodations */
    public function updateStageAccommodations(string $tripId, int $dayNumber, array $accommodations): void
    {
        if (!Uuid::isValid($tripId)) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\Stage s SET s.accommodations = :value WHERE s.trip = :tripId AND s.dayNumber = :dayNumber',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('dayNumber', $dayNumber)
            ->setParameter('value', array_map($this->accommodationToArray(...), $accommodations), 'jsonb')
            ->execute();
    }

    public function updateStageLabels(string $tripId, int $dayNumber, ?string $startLabel, ?string $endLabel): void
    {
        if (!Uuid::isValid($tripId)) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\Stage s SET s.startLabel = :startLabel, s.endLabel = :endLabel WHERE s.trip = :tripId AND s.dayNumber = :dayNumber',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('dayNumber', $dayNumber)
            ->setParameter('startLabel', $startLabel)
            ->setParameter('endLabel', $endLabel)
            ->execute();
    }

    // --- Private helpers ---

    /**
     * Flattens the stage geometries into the route's coordinates for the coverage
     * test, falling back to stage start/end points when geometry is unavailable.
     *
     * @param list<StageDto> $stages
     *
     * @return list<array{lat: float, lon: float}>
     */
    private function stageRoutePoints(array $stages): array
    {
        $points = [];
        foreach ($stages as $stage) {
            foreach ($stage->geometry as $coord) {
                $points[] = ['lat' => $coord->lat, 'lon' => $coord->lon];
            }
        }

        if ([] !== $points) {
            return $points;
        }

        foreach ($stages as $stage) {
            $points[] = ['lat' => $stage->startPoint->lat, 'lon' => $stage->startPoint->lon];
            $points[] = ['lat' => $stage->endPoint->lat, 'lon' => $stage->endPoint->lon];
        }

        return $points;
    }

    private function findTripRequest(string $tripId): ?TripRequest
    {
        if (!Uuid::isValid($tripId)) {
            return null;
        }

        return $this->find(Uuid::fromString($tripId));
    }

    /**
     * Copies user-modifiable fields from a deserialized TripRequest to the managed entity.
     */
    private function copyModifiableFields(TripRequest $managed, TripRequest $source): void
    {
        $managed->sourceUrl = $source->sourceUrl;
        $managed->startDate = $source->startDate;
        $managed->endDate = $source->endDate;
        $managed->fatigueFactor = $source->fatigueFactor;
        $managed->elevationPenalty = $source->elevationPenalty;
        $managed->ebikeMode = $source->ebikeMode;
        $managed->departureHour = $source->departureHour;
        $managed->maxDistancePerDay = $source->maxDistancePerDay;
        $managed->averageSpeed = $source->averageSpeed;
        $managed->enabledAccommodationTypes = $source->enabledAccommodationTypes;
    }

    private function stageDtoToEntity(StageDto $dto, TripRequest $trip, int $position): StageEntity
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
        $entity->setStartLabel($dto->startLabel);
        $entity->setEndLabel($dto->endLabel);
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

        // Resupply → the (repurposed) pois JSONB column.
        $entity->setPois($this->resupplyToArray($dto->resupply ?? new Resupply()));

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
        $tripId = $entity->getTrip()->id;
        \assert($tripId instanceof Uuid);

        $dto = new StageDto(
            tripId: $tripId->toRfc4122(),
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

        $dto->onCycleNetwork = $entity->getOnCycleNetwork();
        $dto->startLabel = $entity->getStartLabel();
        $dto->endLabel = $entity->getEndLabel();

        // Weather
        /** @var array{icon: string, description: string, tempMin: float, tempMax: float, windSpeed: float, windDirection: string, precipitationProbability: int, humidity: int, comfortIndex: int, relativeWindDirection: string}|null $weatherData */
        $weatherData = $entity->getWeather();
        if (null !== $weatherData) {
            $dto->weather = $this->arrayToWeather($weatherData);
        }

        // Alerts
        /** @var list<array{code?: ?string, type: string, message: string, lat?: ?float, lon?: ?float, action?: ?array{kind: string, label: string, payload?: array<string, mixed>}, _class?: string, poiName?: string, poiType?: string, poiLat?: float, poiLon?: float, distanceFromRoute?: int}> $alertsData */
        $alertsData = $entity->getAlerts();
        foreach ($alertsData as $alertData) {
            $dto->addAlert($this->arrayToAlert($alertData));
        }

        // Resupply (stored in the repurposed pois JSONB column).
        $dto->resupply = $this->arrayToResupply($entity->getPois());

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

    /** @return array{code: ?string, type: string, message: string, lat: ?float, lon: ?float, action?: array{kind: string, label: string, payload: array<string, mixed>}, _class?: string, poiName?: string, poiType?: string, poiLat?: float, poiLon?: float, distanceFromRoute?: int} */
    private function alertToArray(Alert $alert): array
    {
        $data = [
            'code' => $alert->code?->value,
            'type' => $alert->type->value,
            'message' => $alert->message,
            'lat' => $alert->lat,
            'lon' => $alert->lon,
        ];

        // The action is persisted whole; the kind filtering happens at emission
        // (TripDetailProvider / StagePayloadMapper), see issue #863.
        if ($alert->action instanceof AlertAction) {
            $data['action'] = [
                'kind' => $alert->action->kind->value,
                'label' => $alert->action->label,
                'payload' => $alert->action->payload,
            ];
        }

        if ($alert instanceof CulturalPoiAlert) {
            $data['_class'] = 'CulturalPoiAlert';
            $data['poiName'] = $alert->poiName;
            $data['poiType'] = $alert->poiType;
            $data['poiLat'] = $alert->poiLat;
            $data['poiLon'] = $alert->poiLon;
            $data['distanceFromRoute'] = $alert->distanceFromRoute;
        } elseif (Alert::class !== $alert::class) {
            throw new \LogicException(\sprintf('Unhandled Alert subclass "%s" in %s. Register it alongside CulturalPoiAlert.', $alert::class, __METHOD__));
        }

        return $data;
    }

    /** @param array{code?: ?string, type: string, message: string, lat?: ?float, lon?: ?float, action?: ?array{kind: string, label: string, payload?: array<string, mixed>}, _class?: string, poiName?: string, poiType?: string, poiLat?: float, poiLon?: float, distanceFromRoute?: int} $data */
    private function arrayToAlert(array $data): Alert
    {
        // Alerts persisted before issue #876 carry no code, and a code retired since
        // then no longer resolves: both degrade to null rather than blowing up the read.
        $code = AlertCode::tryFrom($data['code'] ?? '');
        $type = AlertType::from($data['type']);
        $message = $data['message'];
        $lat = $data['lat'] ?? null;
        $lon = $data['lon'] ?? null;
        // Alerts persisted before issue #863 carry no action at all.
        $actionData = $data['action'] ?? null;
        $action = null !== $actionData
            ? new AlertAction(
                kind: AlertActionKind::from($actionData['kind']),
                label: $actionData['label'],
                payload: $actionData['payload'] ?? [],
            )
            : null;

        if (($data['_class'] ?? null) === 'CulturalPoiAlert') {
            return new CulturalPoiAlert(
                code: $code,
                type: $type,
                message: $message,
                lat: $lat,
                lon: $lon,
                poiName: $data['poiName'] ?? '',
                poiType: $data['poiType'] ?? '',
                poiLat: $data['poiLat'] ?? 0.0,
                poiLon: $data['poiLon'] ?? 0.0,
                distanceFromRoute: $data['distanceFromRoute'] ?? 0,
                action: $action,
            );
        }

        if (null !== ($data['_class'] ?? null)) {
            throw new \LogicException(\sprintf('Unhandled Alert subclass "%s" in %s. Register it alongside CulturalPoiAlert.', $data['_class'], __METHOD__));
        }

        return new Alert(
            code: $code,
            type: $type,
            message: $message,
            lat: $lat,
            lon: $lon,
            action: $action,
        );
    }

    /** @return array{name: string, category: string, lat: float, lon: float, distanceFromStart: ?float, osmType: ?string, osmId: ?int, openingHours: ?string, website: ?string} */
    private function poiToArray(PointOfInterest $poi): array
    {
        return [
            'name' => $poi->name,
            'category' => $poi->category,
            'lat' => $poi->lat,
            'lon' => $poi->lon,
            'distanceFromStart' => $poi->distanceFromStart,
            // Without these the OSM link vanishes on reload and in the shared view,
            // exactly as the accommodation enrichment fields did before issue #870.
            'osmType' => $poi->osmType,
            'osmId' => $poi->osmId,
            'openingHours' => $poi->openingHours,
            'website' => $poi->website,
        ];
    }

    /** @param array{name: string, category: string, lat: float, lon: float, distanceFromStart?: ?float, osmType?: ?string, osmId?: ?int, openingHours?: ?string, website?: ?string} $data */
    private function arrayToPoi(array $data): PointOfInterest
    {
        return new PointOfInterest(
            name: $data['name'],
            category: $data['category'],
            lat: $data['lat'],
            lon: $data['lon'],
            distanceFromStart: $data['distanceFromStart'] ?? null,
            osmType: $data['osmType'] ?? null,
            osmId: $data['osmId'] ?? null,
            openingHours: $data['openingHours'] ?? null,
            website: $data['website'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private function resupplyToArray(Resupply $resupply): array
    {
        return $resupply->map($this->poiToArray(...));
    }

    /** @param array<int|string, mixed> $data */
    private function arrayToResupply(array $data): Resupply
    {
        // Legacy flat POI list (pre-#1099) or empty: nothing to reconstruct until
        // the trip is re-scanned.
        if (!isset($data['foodAtLunch'], $data['foodAtArrival'])) {
            return new Resupply();
        }

        return new Resupply(
            foodAtLunch: $this->poiListFromData($data['foodAtLunch']),
            waterMorning: $this->poiFromData($data['waterMorning'] ?? null),
            waterAfternoon: $this->poiFromData($data['waterAfternoon'] ?? null),
            foodAtArrival: $this->poiListFromData($data['foodAtArrival']),
        );
    }

    /**
     * @return list<PointOfInterest>
     */
    private function poiListFromData(mixed $items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $pois = [];
        foreach ($items as $item) {
            $poi = $this->poiFromData($item);
            if ($poi instanceof PointOfInterest) {
                $pois[] = $poi;
            }
        }

        return $pois;
    }

    private function poiFromData(mixed $item): ?PointOfInterest
    {
        if (!\is_array($item)) {
            return null;
        }

        /** @var array{name: string, category: string, lat: float, lon: float, distanceFromStart?: ?float, osmType?: ?string, osmId?: ?int, openingHours?: ?string, website?: ?string} $poi */
        $poi = $item;

        return $this->arrayToPoi($poi);
    }

    /** @return array{name: string, type: string, lat: float, lon: float, estimatedPriceMin: float, estimatedPriceMax: float, isExactPrice: bool, url: ?string, possibleClosed: bool, distanceToEndPoint: float, source: string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, openingHours: ?string, phone: ?string, osmType: ?string, osmId: ?int} */
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
            // Provisioning-time enrichment (Wikidata, ADR-041) and the source
            // attribution badge: dropped before issue #870, which degraded every
            // reload and the anonymous shared view.
            'source' => $acc->source,
            'description' => $acc->description,
            'imageUrl' => $acc->imageUrl,
            'wikipediaUrl' => $acc->wikipediaUrl,
            'openingHours' => $acc->openingHours,
            // Contact block and OSM identity (issue #873): same trap as the five
            // keys above — omitting them here drops the tel: link and the "see on
            // OSM" affordance on every reload and in the shared view.
            'phone' => $acc->phone,
            'osmType' => $acc->osmType,
            'osmId' => $acc->osmId,
        ];
    }

    /** @param array{name: string, type: string, lat: float, lon: float, estimatedPriceMin: float, estimatedPriceMax: float, isExactPrice: bool, url?: ?string, possibleClosed?: bool, distanceToEndPoint?: float, source?: ?string, description?: ?string, imageUrl?: ?string, wikipediaUrl?: ?string, openingHours?: ?string, phone?: ?string, osmType?: ?string, osmId?: ?int} $data */
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
            // Accommodations persisted before issue #870 carry none of the five
            // enrichment keys: fall back on the constructor defaults.
            source: $data['source'] ?? 'osm',
            description: $data['description'] ?? null,
            imageUrl: $data['imageUrl'] ?? null,
            wikipediaUrl: $data['wikipediaUrl'] ?? null,
            openingHours: $data['openingHours'] ?? null,
            phone: $data['phone'] ?? null,
            osmType: $data['osmType'] ?? null,
            osmId: $data['osmId'] ?? null,
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
