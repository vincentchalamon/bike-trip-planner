<?php

declare(strict_types=1);

namespace App\Repository;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\Event;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Concurrency\VersionPrecondition;
use App\Entity\Stage as StageEntity;
use App\Mapper\StageArrayMapper;
use App\Enum\AlertGroup;
use App\Osm\CoverageRepositoryInterface;
use App\Osm\CycleRouteRepositoryInterface;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * A trip's stages in Postgres: the reconciling writes, the targeted enrichment writes, the
 * reads, and the structural version those writes bump on the trip row.
 */
#[AsAlias(TripStageStoreInterface::class)]
final readonly class DoctrineTripStageStore implements TripStageStoreInterface, MergesGroupWritesAtomically
{
    /** Tolerance (m) between the stage line and a cycle route to count as "on network". */
    private const int CYCLE_NETWORK_TOLERANCE_METERS = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CycleRouteRepositoryInterface $cycleRouteRepository,
        private CoverageRepositoryInterface $coverageRepository,
        private StageArrayMapper $stageMapper,
    ) {
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

        $this->writeStages($trip, $stages, null);
    }

    /**
     * @param list<StageDto> $stages
     * @param int|null       $calendarDays when set, the trip's end date is moved to span that many days, in the same flush
     */
    private function writeStages(TripRequest $trip, array $stages, ?int $calendarDays): void
    {
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

        $this->entityManager->wrapInTransaction(function () use ($trip, $stages, $existing, $cycleNetwork, $outOfZone, $calendarDays): void {
            $incomingIds = array_map(static fn (StageDto $stage): string => $stage->id, $stages);

            // Full regeneration (pacing): the identifier sets are disjoint, so nothing
            // is reconciled and a single bulk DELETE beats N removals (issue #787).
            if ([] !== $existing && [] === array_intersect(array_keys($existing), $incomingIds)) {
                $this->entityManager
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

            if (null !== $calendarDays && $trip->startDate instanceof \DateTimeImmutable) {
                $trip->endDate = $trip->startDate->modify(\sprintf('+%d days', $calendarDays - 1));
            }

            // Any write of the collection is a structural change, whether it comes from a
            // client edit or from a worker regenerating the pacing.
            ++$trip->version;

            foreach ($stages as $position => $stageDto) {
                $stageEntity = $existing[$stageDto->id] ?? null;
                if (!$stageEntity instanceof StageEntity) {
                    $stageEntity = new StageEntity($trip, Uuid::fromString($stageDto->id));
                    $this->entityManager->persist($stageEntity);
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
                    $this->entityManager->remove($stageEntity);
                }
            }

            $this->entityManager->flush();
        });
    }

    /**
     * @param callable(list<StageDto>): list<StageDto> $mutator
     */
    public function mutateStages(string $tripId, callable $mutator, ?int $expectedVersion = null, bool $resequence = false): ?StageWriteResult
    {
        $trip = $this->findTripRequest($tripId);
        $stages = $this->getStages($tripId);
        if (!$trip instanceof TripRequest || null === $stages) {
            return null;
        }

        VersionPrecondition::assert($expectedVersion, $this->getVersion($tripId), $tripId);

        $mutated = $mutator($stages);

        $calendarDays = null;
        if ($resequence) {
            foreach ($mutated as $i => $stage) {
                $stage->dayNumber = $i + 1;
            }

            $calendarDays = \count($mutated) !== \count($stages) ? \count($mutated) : null;
        }

        $this->writeStages($trip, $mutated, $calendarDays);

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
        $stages = $this->entityManager
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
        $row = $this->entityManager->createQuery(
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

    public function getStage(string $tripId, string $stageId): ?StageDto
    {
        if (!Uuid::isValid($tripId) || !Uuid::isValid($stageId)) {
            return null;
        }

        $entity = $this->entityManager->createQuery(
            'SELECT s FROM App\Entity\Stage s WHERE s.trip = :tripId AND s.id = :stageId',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('stageId', Uuid::fromString($stageId))
            // Not optional. The eight targeted enrichment writes are DQL UPDATEs that bypass
            // the unit of work, so a stage already in the identity map is served as the caller
            // last saw it — on the endpoint whose entire job is to show the enrichment that
            // just landed. Same reason {@see self::freshStagesById()} carries it.
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity instanceof StageEntity ? $this->stageEntityToDto($entity) : null;
    }

    /**
     * The trip's shape on a map: day number and geometry, in travel order, nothing else.
     *
     * Same idea as {@see self::getStageGeometry()} one stage wider, and deliberately not the
     * same method — that one projects to 2D and drops `ele` for the planar detour maths, and
     * the map needs the elevation. {@see self::getStages()} would answer this too, at the cost
     * of hydrating weather, alerts, events, the supply timeline and every accommodation, plus
     * one object per geometry point, for a response that keeps two fields.
     *
     * No HINT_REFRESH: unlike the enrichment columns, geometry and day number are only ever
     * written by {@see self::storeStages()}, which flushes managed entities — and the array
     * hydrator does not consult the identity map in the first place.
     *
     * @return list<array{dayNumber: int, geometry: list<array{lat: float, lon: float, ele: float}>}>
     */
    public function getRouteGeometry(string $tripId): array
    {
        if (!Uuid::isValid($tripId)) {
            return [];
        }

        /** @var list<array{dayNumber: int, geometry: list<array{lat: float, lon: float, ele: float}>}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT s.dayNumber, s.geometry FROM App\Entity\Stage s WHERE s.trip = :tripId ORDER BY s.position ASC',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->getResult(AbstractQuery::HYDRATE_ARRAY);

        return $rows;
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
        $this->entityManager->flush();

        return $trip->version;
    }

    public function getStageIdByDayNumber(string $tripId, int $dayNumber): ?string
    {
        if (!Uuid::isValid($tripId)) {
            return null;
        }

        /** @var array{id: Uuid|string}|null $row */
        $row = $this->entityManager->createQuery(
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

        $this->entityManager->createQuery(
            'UPDATE App\Entity\Stage s SET s.weather = :value WHERE s.trip = :tripId AND s.id = :stageId',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('stageId', Uuid::fromString($stageId))
            ->setParameter('value', $weather instanceof WeatherForecast ? $this->stageMapper->weatherForStorage($weather) : null, 'jsonb')
            ->execute();
    }

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
     * Deliberately outside {@see LockingTripStageStore}'s per-trip lock — which is what
     * {@see MergesGroupWritesAtomically} on this class buys: those handlers run in parallel by
     * design, and serialising them behind a lock with a 3-second bounded acquire would turn a
     * burst into failed computations. The lock exists for read-modify-write sequences; this is
     * neither. An implementation that does not merge in place keeps the lock.
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

        $connection = $this->entityManager->getConnection();

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
                        // No `computedAt` alongside: it was a wall-clock timestamp, written on
                        // every alert write and read by nothing — flagged by ADR-070 and again
                        // by ADR-072, removed by ADR-074. Freshness is answered by dispatch
                        // completeness, and "never computed" versus "computed, found nothing"
                        // by the presence of the group key.
                        ['alerts' => array_values($alerts)],
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
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id FROM stage WHERE trip_id = :tripId',
            ['tripId' => $tripId],
        );

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    /** @param list<Event> $events */
    public function updateStageEvents(string $tripId, string $stageId, array $events): void
    {
        $this->updateStageJsonColumn($tripId, $stageId, 'events', array_map($this->stageMapper->event(...), $events));
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

        $this->entityManager->createQuery(
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
        $this->entityManager->createQuery(
            'UPDATE App\Entity\Stage s SET s.pois = :value WHERE s.trip = :tripId AND s.id = :stageId',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('stageId', Uuid::fromString($stageId))
            ->setParameter('value', $this->stageMapper->resupplyForStorage($resupply), 'jsonb')
            ->execute();
    }

    /** @param list<Accommodation> $accommodations */
    public function updateStageAccommodations(string $tripId, string $stageId, array $accommodations): void
    {
        if (!Uuid::isValid($tripId) || !Uuid::isValid($stageId)) {
            return;
        }

        $this->entityManager->createQuery(
            'UPDATE App\Entity\Stage s SET s.accommodations = :value WHERE s.trip = :tripId AND s.id = :stageId',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('stageId', Uuid::fromString($stageId))
            ->setParameter('value', array_map($this->stageMapper->accommodation(...), $accommodations), 'jsonb')
            ->execute();
    }

    public function updateStageLabels(string $tripId, string $stageId, ?string $startLabel, ?string $endLabel): void
    {
        if (!Uuid::isValid($tripId) || !Uuid::isValid($stageId)) {
            return;
        }

        $this->entityManager->createQuery(
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

        $entity->setGeometry(array_map($this->stageMapper->coordinate(...), $dto->geometry));
        $entity->setWeather($dto->weather instanceof WeatherForecast ? $this->stageMapper->weatherForStorage($dto->weather) : null);

        // Enrichment columns are deliberately absent here (ADR-068): alerts, events and the
        // supply timeline belong to the producers that compute them, written through the
        // targeted `updateStage*` methods. Carrying them back from the DTO would let a
        // structural edit replay whatever snapshot the processor happened to read.

        // Resupply → the (repurposed) pois JSONB column.
        $entity->setPois($this->stageMapper->resupplyForStorage($dto->resupply ?? new Resupply()));
        $entity->setAccommodations(array_map($this->stageMapper->accommodation(...), array_values($dto->accommodations)));
        $entity->setSelectedAccommodation(
            $dto->selectedAccommodation instanceof Accommodation ? $this->stageMapper->accommodation($dto->selectedAccommodation) : null,
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

        $weatherData = $entity->getWeather();
        if (null !== $weatherData) {
            $dto->weather = $this->stageMapper->weatherFromStorage($weatherData);
        }

        // Alerts: handed back exactly as their producer wrote them, group by group. No
        // reconstruction into Alert — that is what used to drop the richer fields.
        foreach ($entity->getAlertsByGroup() as $group => $entry) {
            $dto->alertsByGroup[$group] = array_values($entry['alerts'] ?? []);
        }

        $dto->supplyTimeline = $entity->getSupplyTimeline();

        foreach ($entity->getEvents() as $eventData) {
            $dto->addEvent($this->stageMapper->eventFromArray($eventData));
        }

        // Resupply (stored in the repurposed pois JSONB column).
        $dto->resupply = $this->stageMapper->resupplyFromStorage($entity->getPois());

        foreach ($entity->getAccommodations() as $accData) {
            $dto->addAccommodation($this->stageMapper->accommodationFromArray($accData));
        }

        $selectedData = $entity->getSelectedAccommodation();
        if (null !== $selectedData) {
            $dto->selectedAccommodation = $this->stageMapper->accommodationFromArray($selectedData);
        }

        return $dto;
    }

    private function findTripRequest(string $tripId): ?TripRequest
    {
        if (!Uuid::isValid($tripId)) {
            return null;
        }

        return $this->entityManager->find(TripRequest::class, Uuid::fromString($tripId));
    }
}
