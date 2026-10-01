<?php

declare(strict_types=1);

namespace App\Repository;

use App\ComputationTracker\ComputationStatusStore;
use App\ApiResource\TripRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TripRequest>
 */
#[AsAlias(TripRequestRepositoryInterface::class)]
final class DoctrineTripRequestRepository extends ServiceEntityRepository implements TripRequestRepositoryInterface, ComputationStatusStore, OwnedTripFinderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TripRequest::class);
    }

    /**
     * Creates the trip, or applies the settings to the one already there.
     *
     * Both branches go through {@see self::copyModifiableFields()}, and that is the whole point.
     * This used to persist the caller's object outright, which made every public property of
     * {@see TripRequest} settable from a request body — `status`, `version`, `createdAt`,
     * `computationStatus`, `outOfZone`, `sourceType` included. `#[ApiProperty(writable: false)]`
     * did not stand in the way and never could: the serializer only consults `isWritable()` for
     * a class that is an `#[ApiResource]` ({@see \ApiPlatform\Serializer\AbstractItemNormalizer::getAllowedAttributes()}
     * hands anything else to Symfony's own filter, which sorts by serialization group and finds
     * none declared here). The attribute describes the published schema; this list is what
     * guards the row, and there is deliberately only one of it.
     *
     * What that cost: a `status` of `ready` made a trip announce itself structurally computed
     * before any pacing ran (ADR-043), a chosen `version` seeded the optimistic-concurrency
     * counter, and a chosen `createdAt` decided where the trip sorted in the owner's list.
     *
     * Only `POST /trips` was exposed, because it is the only creation whose DTO comes out of a
     * deserializer. {@see \App\Controller\GpxUploadController} builds its own `TripRequest` and
     * copies a bounded list of form fields into it, so it never had the hole — but it calls this
     * same method, which is why it is covered by a test too.
     *
     * The owner rides along because both callers set it from the authenticated token just before
     * calling, never from the body. `locale` is not copied either: the caller passes the
     * account's as `$locale`, written in the same flush rather than by a second `storeLocale()`.
     * `sourceType` is not lost: `storeSourceType()` owns it.
     */
    public function initializeTrip(string $tripId, TripRequest $request, ?string $locale = null): void
    {
        $trip = $this->findTripRequest($tripId);
        if ($trip instanceof TripRequest) {
            $this->copyModifiableFields($trip, $request);
        } else {
            $trip = new TripRequest(Uuid::fromString($tripId));
            $this->copyModifiableFields($trip, $request);
            $trip->user = $request->user;

            $this->getEntityManager()->persist($trip);
        }

        if (null !== $locale) {
            $trip->locale = $locale;
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

    public function isOwnedBy(string $tripId, User $owner): bool
    {
        if (!Uuid::isValid($tripId)) {
            return false;
        }

        $count = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.id = :tripId')
            ->andWhere('t.user = :user')
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('user', $owner)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function findPageOwnedBy(User $owner, ?string $title, ?\DateTimeImmutable $startsFrom, ?\DateTimeImmutable $endsBy, int $offset, int $limit): array
    {
        /** @var list<TripRequest> $trips */
        $trips = $this->ownedBy($owner, $title, $startsFrom, $endsBy)
            ->orderBy('t.createdAt', \SortDirection::Descending)
            // createdAt alone is not a total order: two trips created in the same second sit
            // in an order the database is free to change between queries, so a tie straddling
            // a page boundary is served twice or not at all. The identifier is a UUID v7, so
            // it breaks the tie in the same direction time runs.
            ->addOrderBy('t.id', \SortDirection::Descending)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $trips;
    }

    public function countOwnedBy(User $owner, ?string $title, ?\DateTimeImmutable $startsFrom, ?\DateTimeImmutable $endsBy): int
    {
        // One row per trip and no join, so no DISTINCT to pay for.
        return (int) $this->ownedBy($owner, $title, $startsFrom, $endsBy)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findAllOwnedBy(User $owner): array
    {
        /** @var list<TripRequest> $trips */
        $trips = $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->setParameter('user', $owner)
            ->orderBy('t.createdAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $trips;
    }

    /**
     * Read as an aggregate rather than summed over a fetch-joined page, which hydrated eight
     * JSONB columns per stage — geometry included — to produce a float and an int.
     *
     * Rest days are excluded here rather than in a join condition, and the query is separate
     * rather than a GROUP BY on the page: a left join carrying that predicate in its WHERE
     * turns into an inner join, and a trip with no stages — the state every new trip is in —
     * would drop out of the list entirely.
     */
    public function stageTotalsByTrip(array $tripIds): array
    {
        if ([] === $tripIds) {
            return [];
        }

        /** @var list<array{tripId: string, distance: numeric-string|float|null, stages: int}> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(s.trip) AS tripId, SUM(s.distance) AS distance, COUNT(s.id) AS stages
             FROM App\Entity\Stage s
             WHERE s.trip IN (:tripIds) AND s.isRestDay = false
             GROUP BY s.trip',
        )
            ->setParameter('tripIds', $tripIds)
            ->getResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[Uuid::fromString($row['tripId'])->toRfc4122()] = [(float) $row['distance'], (int) $row['stages']];
        }

        return $totals;
    }

    /**
     * A scalar read rather than the stages themselves: a fetch-join hydrated eight JSONB
     * columns per stage — geometry, weather, alerts, accommodations — none of which the export
     * ships. It carries its own ORDER BY, because the `#[ORM\OrderBy]` on the association only
     * applies when the collection is the thing being loaded; sorting globally by position keeps
     * each trip's own rows ascending.
     */
    public function stageSummariesByTrip(array $tripIds): array
    {
        if ([] === $tripIds) {
            return [];
        }

        /** @var list<array{tripId: string, dayNumber: int, label: string|null, distance: float, elevation: float}> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(s.trip) AS tripId, s.dayNumber, s.label, s.distance, s.elevation
             FROM App\Entity\Stage s
             WHERE s.trip IN (:tripIds)
             ORDER BY s.position ASC',
        )
            ->setParameter('tripIds', $tripIds)
            ->getResult();

        $byTripId = [];
        foreach ($rows as $row) {
            $byTripId[Uuid::fromString($row['tripId'])->toRfc4122()][] = [
                'dayNumber' => $row['dayNumber'],
                'label' => $row['label'],
                'distance' => $row['distance'],
                'elevation' => $row['elevation'],
            ];
        }

        return $byTripId;
    }

    /**
     * Mirrors the enrichment status map onto the trip row (ADR-072).
     *
     * A targeted UPDATE rather than a managed-entity write: this runs from a worker that has
     * no business hydrating the aggregate.
     *
     * @param array<string, string> $statuses
     */
    public function replaceComputationStatus(string $tripId, array $statuses): void
    {
        if (!Uuid::isValid($tripId)) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'UPDATE App\ApiResource\TripRequest t SET t.computationStatus = :value WHERE t.id = :tripId',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->setParameter('value', $statuses, 'jsonb')
            ->execute();
    }

    /**
     * Merges one computation's status into the map, in the database rather than in PHP.
     *
     * Five workers settle concurrently and the tracker's lock covers only its own Redis
     * read-modify-write, not a round trip to Postgres on the far side of it. Reading the map
     * here and writing it back whole would let the worker that read first and landed last
     * erase another's entry — invisibly, since the mirror is only read once the cache is
     * gone. `||` merges the one key server-side, so arrival order stops mattering.
     */
    public function mergeComputationStatus(string $tripId, string $computation, string $status): void
    {
        if (!Uuid::isValid($tripId)) {
            return;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE trip SET computation_status = computation_status || CAST(:entry AS jsonb) WHERE id = :tripId',
            [
                'entry' => json_encode([$computation => $status], \JSON_THROW_ON_ERROR),
                'tripId' => $tripId,
            ],
        );
    }

    /**
     * The mirrored map, or null when the trip is unknown.
     *
     * An empty map means "nothing has settled yet", which is not the same as null: the
     * caller distinguishes an unknown trip from one whose computations are all still running.
     *
     * Reads the column rather than a hydrated entity: the two writers above go straight to
     * SQL, which leaves an already-managed `TripRequest` holding the old map.
     *
     * @return array<string, string>|null
     */
    public function getComputationStatus(string $tripId): ?array
    {
        if (!Uuid::isValid($tripId)) {
            return null;
        }

        /** @var array{computationStatus: array<string, string>}|null $row */
        $row = $this->getEntityManager()->createQuery(
            'SELECT t.computationStatus AS computationStatus FROM App\ApiResource\TripRequest t WHERE t.id = :tripId',
        )
            ->setParameter('tripId', Uuid::fromString($tripId))
            ->getOneOrNullResult(AbstractQuery::HYDRATE_ARRAY);

        return $row['computationStatus'] ?? null;
    }

    /**
     * The mirrored maps of several trips, in one query.
     *
     * @param list<string> $tripIds
     *
     * @return array<string, array<string, string>>
     */
    public function getComputationStatusBatch(array $tripIds): array
    {
        $uuids = array_values(array_filter(
            array_map(static fn (string $id): ?Uuid => Uuid::isValid($id) ? Uuid::fromString($id) : null, $tripIds),
        ));

        if ([] === $uuids) {
            return [];
        }

        /** @var list<array{id: Uuid|string, computationStatus: array<string, string>}> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT t.id AS id, t.computationStatus AS computationStatus FROM App\ApiResource\TripRequest t WHERE t.id IN (:ids)',
        )
            ->setParameter('ids', $uuids)
            ->getArrayResult();

        $byTripId = [];
        foreach ($rows as $row) {
            // Same hedge as DoctrineTripStageStore::getStageIdByDayNumber(): array hydration of a
            // uuid column is not contractually an object.
            $id = $row['id'] instanceof Uuid ? $row['id']->toRfc4122() : $row['id'];
            $byTripId[$id] = $row['computationStatus'];
        }

        return $byTripId;
    }

    private function ownedBy(User $owner, ?string $title, ?\DateTimeImmutable $startsFrom, ?\DateTimeImmutable $endsBy): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $owner);

        if (null !== $title && '' !== $title) {
            $qb->andWhere('LOWER(t.title) LIKE LOWER(:title)')
                ->setParameter('title', '%'.addcslashes($title, '%_').'%');
        }

        if ($startsFrom instanceof \DateTimeImmutable) {
            $qb->andWhere('t.startDate >= :startDate')
                ->setParameter('startDate', $startsFrom);
        }

        if ($endsBy instanceof \DateTimeImmutable) {
            $qb->andWhere('t.endDate <= :endDate')
                ->setParameter('endDate', $endsBy);
        }

        return $qb;
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
        // Listed last because it was missing, and the omission was invisible: $managed and
        // $source are the same managed instance whenever the caller read the trip through this
        // repository, so every assignment here is a self-assignment. It only does anything for a
        // caller holding a detached copy — which is exactly what storeRequest() promises to support.
        $managed->title = $source->title;
    }
}
