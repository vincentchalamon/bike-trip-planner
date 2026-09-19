<?php

declare(strict_types=1);

namespace App\Repository;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\Event;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Concurrency\VersionPrecondition;
use App\Entity\Stage as StageEntity;
use App\Mapper\EventArrayMapper;
use App\Enum\AlertGroup;
use App\Osm\CoverageRepositoryInterface;
use App\Osm\CycleRouteRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Query;
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
        private readonly EventArrayMapper $eventMapper,
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
     * batch (#1124). Started on or before the day and not yet ended — a trip whose
     * `endDate` is still unset counts as not-yet-ended, so an open-ended trip is
     * kept rather than silently dropped. No fixed look-back window, so a long-haul
     * trip is never excluded by length; the caller checks a non-rest stage actually
     * falls on that day.
     *
     * @return list<TripRequest>
     */
    public function findOwnedTripsCoveringDate(\DateTimeImmutable $date): array
    {
        /** @var list<TripRequest> $trips */
        // Fetch-join the stages: stageOnDay() iterates t.stages per trip, so a lazy
        // OneToMany would fire one SELECT per trip (N+1) on a batch that runs twice
        // a day.
        $trips = $this->createQueryBuilder('t')
            ->leftJoin('t.stages', 's')
            ->addSelect('s')
            ->andWhere('t.user IS NOT NULL')
            ->andWhere('t.startDate IS NOT NULL')
            ->andWhere('t.startDate <= :date')
            ->andWhere('t.endDate IS NULL OR t.endDate >= :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();

        return $trips;
    }

    /**
     * Reconciles the persisted rows against the incoming stage identifiers: updates the
     * ones that survive, inserts the new ones, deletes the disappeared ones. Identity
     * therefore survives every write, which is what makes an addressable stage — and the
     * per-stage targeted writes below — possible at all (ADR-066).
     *
     * @param list<StageDto> $stages
     */
    public function storeStages(string $tripId, array $stages): void
    {
        $trip = $this->findTripRequest($tripId);
        if (!$trip instanceof TripRequest) {
            return;
        }

        $this->assertDistinctIdentifiers($stages);

        $existing = $this->freshStagesById($trip);

        // The on-cycle-network fraction and out-of-zone flag are derived purely
        // from the route geometry, so they only change when the geometry does
        // (initial compute, route recalculation). storeStages() also runs on
        // every enrichment/edit pass (weather, accommodation select, distance
        // edit), which leaves the geometry untouched — guard the two heavy PostGIS
        // scans behind a geometry-change check so frequent edits reuse the already
        // persisted values (issue #775, perf review on #787).
        [$cycleNetwork, $outOfZone] = $this->geometryUnchanged($existing, $stages)
            ? [$this->persistedCycleNetwork($existing), $trip->outOfZone]
            : $this->computeRouteMetrics($stages);

        $this->getEntityManager()->wrapInTransaction(function () use ($trip, $stages, $existing, $cycleNetwork, $outOfZone): void {
            $incomingIds = array_map(static fn (StageDto $stage): string => $stage->id, $stages);

            // Full regeneration (pacing): the identifier sets are disjoint, so nothing
            // is reconciled and a single bulk DELETE beats N removals (issue #787).
            if ([] !== $existing && [] === array_intersect(array_keys($existing), $incomingIds)) {
                $this->getEntityManager()
                    ->createQuery('DELETE FROM App\Entity\Stage s WHERE s.trip = :trip')
                    ->setParameter('trip', $trip)
                    ->execute();
                $trip->clearStages(); // Keep UoW in sync with the deleted rows
                $existing = [];
            }

            // Mutate the managed entity inside the transaction so a flush failure
            // does not leave a stale out-of-zone flag on the in-memory entity
            // (correctness review on #787).
            $trip->outOfZone = $outOfZone;

            // Any write of the collection is a structural change, whether it comes from a
            // client edit or from a worker regenerating the pacing.
            ++$trip->version;

            foreach ($stages as $position => $stageDto) {
                $stageEntity = $existing[$stageDto->id] ?? null;
                if (!$stageEntity instanceof StageEntity) {
                    $stageEntity = new StageEntity($trip, Uuid::fromString($stageDto->id));
                    $this->getEntityManager()->persist($stageEntity);
                }

                $this->applyDtoToEntity($stageDto, $stageEntity, $position);
                $stageEntity->setOnCycleNetwork($cycleNetwork[$stageDto->id] ?? 0.0);
                // addStage() guards on contains(), so this also re-syncs the owning
                // collection with rows it never saw (inserted by another process).
                $trip->addStage($stageEntity);
            }

            foreach ($existing as $id => $stageEntity) {
                if (!\in_array($id, $incomingIds, true)) {
                    $trip->removeStage($stageEntity);
                    $this->getEntityManager()->remove($stageEntity);
                }
            }

            $this->getEntityManager()->flush();
        });
    }

    /**
     * @param callable(list<StageDto>): list<StageDto> $mutator
     */
    public function mutateStages(string $tripId, callable $mutator, ?int $expectedVersion = null): ?StageWriteResult
    {
        $stages = $this->getStages($tripId);
        if (null === $stages) {
            return null;
        }

        VersionPrecondition::assert($expectedVersion, $this->getVersion($tripId), $tripId);

        $mutated = $mutator($stages);
        $this->storeStages($tripId, $mutated);

        return new StageWriteResult($mutated, $this->getVersion($tripId) ?? 1);
    }

    /**
     * Re-reads the persisted stages from the database, keyed by identifier.
     *
     * Never built from $trip->stages: the owning collection is initialised by the
     * caller's read and is never re-synchronised afterwards, so rows inserted or deleted
     * by a concurrent worker stay invisible to it. HINT_REFRESH also overwrites the
     * identity map, without which the re-read silently returns the caller's stale
     * entities. Both behaviours are pinned by
     * {@see \App\Tests\Integration\Repository\DoctrineStageRefreshSemanticsTest}.
     *
     * @return array<string, StageEntity>
     */
    private function freshStagesById(TripRequest $trip): array
    {
        /** @var list<StageEntity> $stages */
        $stages = $this->getEntityManager()
            ->createQuery('SELECT s FROM App\Entity\Stage s WHERE s.trip = :trip ORDER BY s.position ASC')
            ->setParameter('trip', $trip)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        $byId = [];
        foreach ($stages as $stage) {
            $byId[$stage->getId()->toRfc4122()] = $stage;
        }

        return $byId;
    }

    /**
     * Two DTOs sharing an identifier would reconcile onto the same row and surface as an
     * opaque primary-key violation at flush time. Fail where the cause is visible.
     *
     * @param list<StageDto> $stages
     */
    private function assertDistinctIdentifiers(array $stages): void
    {
        $ids = array_map(static fn (StageDto $stage): string => $stage->id, $stages);

        if (\count(array_unique($ids)) !== \count($ids)) {
            throw new \LogicException('Stages to store carry duplicate identifiers: a stage DTO was cloned instead of being created.');
        }
    }

    /**
     * Computes the geometry-derived trip-detail metrics: the per-stage on-cycle-network
     * fraction and the out-of-zone flag.
     *
     * The fractions are keyed by stage identifier, not by position: a move reorders the
     * stages, and an index-aligned map would then apply each fraction to whichever stage
     * now sits at that position, silently scrambling the values.
     *
     * @param list<StageDto> $stages
     *
     * @return array{0: array<string, float>, 1: bool}
     */
    private function computeRouteMetrics(array $stages): array
    {
        $fractions = $this->cycleRouteRepository->onNetworkFractions(
            array_map(
                static fn (StageDto $stage): array => array_map(
                    static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon],
                    $stage->geometry,
                ),
                $stages,
            ),
            self::CYCLE_NETWORK_TOLERANCE_METERS,
        );

        $cycleNetwork = [];
        foreach ($stages as $index => $stage) {
            $cycleNetwork[$stage->id] = $fractions[$index] ?? 0.0;
        }

        $outOfZone = $this->coverageRepository->isRouteOutOfZone($this->stageRoutePoints($stages));

        return [$cycleNetwork, $outOfZone];
    }

    /**
     * Returns true when the incoming stage geometry (and endpoints) match what is
     * already persisted, so the geometry-derived PostGIS metrics can be reused.
     *
     * Matched by identifier rather than by position, so a pure reorder is correctly
     * recognised as leaving the geometry untouched.
     *
     * @param array<string, StageEntity> $existing
     * @param list<StageDto>             $stages
     */
    private function geometryUnchanged(array $existing, array $stages): bool
    {
        if (\count($existing) !== \count($stages)) {
            return false;
        }

        return array_all(
            $stages,
            fn (StageDto $stage): bool => isset($existing[$stage->id])
                && $this->stageGeometrySignature($stage) === $this->entityGeometrySignature($existing[$stage->id]),
        );
    }

    /**
     * @param array<string, StageEntity> $existing
     *
     * @return array<string, float> the persisted on-cycle-network fractions, keyed by stage identifier
     */
    private function persistedCycleNetwork(array $existing): array
    {
        return array_map(
            static fn (StageEntity $entity): float => $entity->getOnCycleNetwork(),
            $existing,
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

    /**
     * @return list<StageDto>|null
     */
    public function getStages(string $tripId): ?array
    {
        $trip = $this->findTripRequest($tripId);
        if (!$trip instanceof TripRequest) {
            return null;
        }

        // Read through the refreshing query rather than the owning collection. The
        // targeted per-stage writes are DQL UPDATEs that bypass the unit of work, so a
        // caller that read the trip before one of them would otherwise be served its own
        // stale entities and never see the enrichment that just landed.
        $result = [];
        foreach ($this->freshStagesById($trip) as $stageEntity) {
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
    public function getStageGeometry(string $tripId, string $stageId): ?array
    {
        if (!Uuid::isValid($tripId) || !Uuid::isValid($stageId)) {
            return null;
        }

        /** @var array{geometry: list<array{lat: float, lon: float, ele: float}>}|null $row */
        $row = $this->getEntityManager()->createQuery(
            'SELECT s.geometry FROM App\Entity\Stage s WHERE s.trip = :tripId AND s.id = :stageId',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('stageId', Uuid::fromString($stageId))
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);

        if (null === $row || [] === $row['geometry']) {
            return null;
        }

        return array_map(
            static fn (array $point): array => ['lat' => $point['lat'], 'lon' => $point['lon']],
            $row['geometry'],
        );
    }

    public function getVersion(string $tripId): ?int
    {
        return $this->findTripRequest($tripId)?->version;
    }

    public function bumpVersion(string $tripId, ?int $expectedVersion = null): int
    {
        $trip = $this->findTripRequest($tripId);
        if (!$trip instanceof TripRequest) {
            return 0;
        }

        VersionPrecondition::assert($expectedVersion, $trip->version, $tripId);

        ++$trip->version;
        $this->getEntityManager()->flush();

        return $trip->version;
    }

    public function getStageIdByDayNumber(string $tripId, int $dayNumber): ?string
    {
        if (!Uuid::isValid($tripId)) {
            return null;
        }

        /** @var array{id: Uuid|string}|null $row */
        $row = $this->getEntityManager()->createQuery(
            'SELECT s.id FROM App\Entity\Stage s WHERE s.trip = :tripId AND s.dayNumber = :dayNumber ORDER BY s.position ASC',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('dayNumber', $dayNumber)
            ->setMaxResults(1)
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);

        if (null === $row) {
            return null;
        }

        return $row['id'] instanceof Uuid ? $row['id']->toRfc4122() : $row['id'];
    }

    // Atomic per-stage UPDATE of one JSONB column, keyed by the stage identifier: lets parallel
    // enrichment handlers persist only their own column instead of the whole-collection
    // read-modify-write of storeStages() (which let a slow handler overwrite a sibling's
    // freshly-written column — recette #649). One literal DQL per column (a dynamic,
    // sprintf-built query string is not validated by phpstan-doctrine and avoids any
    // column-name interpolation). The value is bound as a single 'jsonb' parameter —
    // without the explicit type Doctrine infers ArrayParameterType and expands the list
    // into $1, $2, … .

    public function updateStageWeather(string $tripId, string $stageId, ?WeatherForecast $weather): void
    {
        if (!Uuid::isValid($tripId) || !Uuid::isValid($stageId)) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\Stage s SET s.weather = :value WHERE s.trip = :tripId AND s.id = :stageId',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('stageId', Uuid::fromString($stageId))
            ->setParameter('value', $weather instanceof WeatherForecast ? $this->weatherToArray($weather) : null, 'jsonb')
            ->execute();
    }

    /** @param list<Alert> $alerts */
    /** @param list<array<string, mixed>> $alerts */
    public function updateStageAlertsForGroup(string $tripId, string $stageId, AlertGroup $group, array $alerts): void
    {
        if (!Uuid::isValid($tripId) || !Uuid::isValid($stageId)) {
            return;
        }

        $this->writeAlertGroup($tripId, [$stageId => $alerts], $group);
    }

    /** @param array<string, list<array<string, mixed>>> $alertsByStageId */
    public function updateTripAlertsForGroup(string $tripId, AlertGroup $group, array $alertsByStageId): void
    {
        if (!Uuid::isValid($tripId)) {
            return;
        }

        // Every stage of the trip, not only the ones carrying alerts: a stage that dropped
        // out of the new set has to lose the group rather than keep a stale entry.
        $all = [];
        foreach ($this->stageIdsOf($tripId) as $stageId) {
            $all[$stageId] = $alertsByStageId[$stageId] ?? [];
        }

        $this->writeAlertGroup($tripId, $all, $group);
    }

    /**
     * Merges one group into `alerts_by_group`, one UPDATE per stage, no application lock.
     *
     * The merge is Postgres's, not ours: `jsonb_set` replaces a single key and leaves the
     * other twelve untouched, so a dozen enrichment handlers finishing at once all survive.
     * Under READ COMMITTED a blocked UPDATE re-evaluates against the row version the winner
     * committed, which is exactly what makes that true.
     *
     * Deliberately outside {@see LockingTripRequestRepository}'s per-trip lock: those handlers
     * run in parallel by design, and serialising them behind a lock with a 3-second bounded
     * acquire would turn a burst into failed computations. The lock exists for read-modify-write
     * sequences; this is neither.
     *
     * Raw SQL because DQL cannot express a JSONB path write. Same reason and same shape as
     * {@see MagicLinkRepository::consume()}.
     *
     * @param array<string, list<array<string, mixed>>> $alertsByStageId
     */
    private function writeAlertGroup(string $tripId, array $alertsByStageId, AlertGroup $group): void
    {
        if ([] === $alertsByStageId) {
            return;
        }

        $computedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
        $connection = $this->getEntityManager()->getConnection();

        foreach ($alertsByStageId as $stageId => $alerts) {
            if (!Uuid::isValid($stageId)) {
                continue;
            }

            $connection->executeStatement(
                <<<'SQL'
                    UPDATE stage
                    SET alerts_by_group = jsonb_set(
                        -- An empty PHP array encodes as `[]`, not `{}`, so a stage that has
                        -- never been enriched holds a JSON *array*; jsonb_set refuses a text
                        -- path against one. Normalise to an object before merging.
                        CASE WHEN jsonb_typeof(alerts_by_group) = 'object'
                             THEN alerts_by_group
                             ELSE '{}'::jsonb END,
                        ARRAY[CAST(:group AS text)],
                        CAST(:entry AS jsonb),
                        true
                    )
                    WHERE trip_id = :tripId AND id = :stageId
                    SQL,
                [
                    'group' => $group->value,
                    'entry' => json_encode(
                        ['computedAt' => $computedAt, 'alerts' => array_values($alerts)],
                        \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
                    ),
                    'tripId' => $tripId,
                    'stageId' => $stageId,
                ],
            );
        }

        // Nothing to invalidate by hand: the rows changed behind the ORM's back, but every
        // read of them goes through the HINT_REFRESH query ADR-066 introduced, which
        // overwrites the identity map rather than trusting it.
    }

    /**
     * @return list<string>
     */
    private function stageIdsOf(string $tripId): array
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id FROM stage WHERE trip_id = :tripId',
            ['tripId' => $tripId],
        );

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    /** @param list<Event> $events */
    public function updateStageEvents(string $tripId, string $stageId, array $events): void
    {
        $this->updateStageJsonColumn($tripId, $stageId, 'events', array_map($this->eventMapper->toArray(...), $events));
    }

    /** @param list<array<string, mixed>> $markers */
    public function updateStageSupplyTimeline(string $tripId, string $stageId, array $markers): void
    {
        $this->updateStageJsonColumn($tripId, $stageId, 'supplyTimeline', $markers);
    }

    /** @param list<array<string, mixed>> $value */
    private function updateStageJsonColumn(string $tripId, string $stageId, string $field, array $value): void
    {
        if (!Uuid::isValid($tripId) || !Uuid::isValid($stageId)) {
            return;
        }

        $this->getEntityManager()->createQuery(
            \sprintf('UPDATE App\Entity\Stage s SET s.%s = :value WHERE s.trip = :tripId AND s.id = :stageId', $field),
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('stageId', Uuid::fromString($stageId))
            ->setParameter('value', array_values($value), 'jsonb')
            ->execute();
    }

    public function updateStageResupply(string $tripId, string $stageId, Resupply $resupply): void
    {
        if (!Uuid::isValid($tripId) || !Uuid::isValid($stageId)) {
            return;
        }

        // Stored in the (JSONB) `pois` column — repurposed to hold the curated
        // resupply object since the raw corridor set is no longer persisted (#1099).
        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\Stage s SET s.pois = :value WHERE s.trip = :tripId AND s.id = :stageId',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('stageId', Uuid::fromString($stageId))
            ->setParameter('value', $this->resupplyToArray($resupply), 'jsonb')
            ->execute();
    }

    /** @param list<Accommodation> $accommodations */
    public function updateStageAccommodations(string $tripId, string $stageId, array $accommodations): void
    {
        if (!Uuid::isValid($tripId) || !Uuid::isValid($stageId)) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\Stage s SET s.accommodations = :value WHERE s.trip = :tripId AND s.id = :stageId',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('stageId', Uuid::fromString($stageId))
            ->setParameter('value', array_map($this->accommodationToArray(...), $accommodations), 'jsonb')
            ->execute();
    }

    public function updateStageLabels(string $tripId, string $stageId, ?string $startLabel, ?string $endLabel): void
    {
        if (!Uuid::isValid($tripId) || !Uuid::isValid($stageId)) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\Stage s SET s.startLabel = :startLabel, s.endLabel = :endLabel WHERE s.trip = :tripId AND s.id = :stageId',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('stageId', Uuid::fromString($stageId))
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

    /**
     * Writes the whole row from the DTO, unconditionally.
     *
     * Every column is assigned, including the enrichment ones: on an existing entity a
     * conditional write would mean "keep the persisted weather but wipe the persisted
     * alerts", a half-applied partition nobody could defend. The policy is therefore
     * explicit — storeStages() owns the entire row — and stays that way until the
     * enrichment columns move to targeted writes of their own.
     */
    private function applyDtoToEntity(StageDto $dto, StageEntity $entity, int $position): void
    {
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
        $entity->setWeather($dto->weather instanceof WeatherForecast ? $this->weatherToArray($dto->weather) : null);

        // Enrichment columns are deliberately absent here (ADR-068): alerts, events and the
        // supply timeline belong to the producers that compute them, written through the
        // targeted `updateStage*` methods. Carrying them back from the DTO would let a
        // structural edit replay whatever snapshot the processor happened to read.

        // Resupply → the (repurposed) pois JSONB column.
        $entity->setPois($this->resupplyToArray($dto->resupply ?? new Resupply()));

        // Accommodations: Accommodation[] → list<array>
        $accommodations = [];
        foreach ($dto->accommodations as $accommodation) {
            $accommodations[] = $this->accommodationToArray($accommodation);
        }

        $entity->setAccommodations($accommodations);

        // Selected accommodation
        $entity->setSelectedAccommodation(
            $dto->selectedAccommodation instanceof Accommodation ? $this->accommodationToArray($dto->selectedAccommodation) : null,
        );
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
            id: $entity->getId()->toRfc4122(),
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

        // Alerts: handed back exactly as their producer wrote them, group by group. No
        // reconstruction into Alert — that is what used to drop the richer fields.
        foreach ($entity->getAlertsByGroup() as $group => $entry) {
            $dto->alertsByGroup[$group] = array_values($entry['alerts'] ?? []);
        }

        $dto->supplyTimeline = $entity->getSupplyTimeline();

        foreach ($entity->getEvents() as $eventData) {
            $dto->addEvent($this->eventMapper->fromArray($eventData));
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

    /** @return array{name: string, type: string, lat: float, lon: float, estimatedPriceMin: float, estimatedPriceMax: float, isExactPrice: bool, url: ?string, possibleClosed: bool, distanceToEndPoint: float, source: string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, openingHours: ?string, phone: ?string, address: ?string, osmType: ?string, osmId: ?int} */
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
            'address' => $acc->address,
            'osmType' => $acc->osmType,
            'osmId' => $acc->osmId,
        ];
    }

    /** @param array{name: string, type: string, lat: float, lon: float, estimatedPriceMin: float, estimatedPriceMax: float, isExactPrice: bool, url?: ?string, possibleClosed?: bool, distanceToEndPoint?: float, source?: ?string, description?: ?string, imageUrl?: ?string, wikipediaUrl?: ?string, openingHours?: ?string, phone?: ?string, address?: ?string, osmType?: ?string, osmId?: ?int} $data */
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
            address: $data['address'] ?? null,
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
