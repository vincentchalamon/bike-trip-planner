<?php

declare(strict_types=1);

namespace App\State;

use App\Enum\ComputationStatus;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripListItem;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\State\Mcp\McpArguments;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Provides a paginated, filterable list of trips from PostgreSQL.
 *
 * Only returns trips owned by the current authenticated user.
 *
 * Supports the following query parameters:
 *   - page (integer, default 1)
 *   - title (string, partial case-insensitive match)
 *   - startDate / endDate (date strings, inclusive range filter on trip start/end dates)
 *
 * @implements ProviderInterface<TripListItem>
 */
final readonly class TripCollectionProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Pagination $pagination,
        private Security $security,
        private ComputationTrackerInterface $computationTracker,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripListPaginator
    {
        // On HTTP these are the query parameters; on an MCP call they are the tool's
        // arguments, which reach neither the query string nor `$context['filters']` on their
        // own. Feeding them back into the context is what lets `Pagination` see `page` and
        // `itemsPerPage` too — including the maximum `api_platform.php` publishes, which a
        // hand-rolled limit here would be free to drift away from.
        $filters = McpArguments::filters($context);
        $context['filters'] = $filters;

        [$page, , $limit] = $this->pagination->getPagination($operation, $context);

        $user = $this->security->getUser();

        \assert($user instanceof User);

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t')
            ->from(TripRequest::class, 't')
            ->orderBy('t.createdAt', \SortDirection::Descending)
            // createdAt alone is not a total order: two trips created in the same second sit
            // in an order the database is free to change between queries, so a tie straddling
            // a page boundary is served twice or not at all. The identifier is a UUID v7, so
            // it breaks the tie in the same direction time runs.
            ->addOrderBy('t.id', \SortDirection::Descending)
            ->andWhere('t.user = :user')
            ->setParameter('user', $user);

        // Filter by title (partial, case-insensitive)
        if (isset($filters['title']) && '' !== $filters['title'] && is_string($filters['title'])) {
            $qb->andWhere('LOWER(t.title) LIKE LOWER(:title)')
                ->setParameter('title', '%'.addcslashes($filters['title'], '%_').'%');
        }

        // Filter by startDate (trips starting on or after this date)
        if (!empty($filters['startDate']) && is_string($filters['startDate'])) {
            try {
                $start = new \DateTimeImmutable($filters['startDate']);
                $qb->andWhere('t.startDate >= :startDate')
                    ->setParameter('startDate', $start);
            } catch (\Exception) {
                // Ignore invalid date values
            }
        }

        // Filter by endDate (trips ending on or before this date)
        if (!empty($filters['endDate']) && is_string($filters['endDate'])) {
            try {
                $end = new \DateTimeImmutable($filters['endDate']);
                $qb->andWhere('t.endDate <= :endDate')
                    ->setParameter('endDate', $end);
            } catch (\Exception) {
                // Ignore invalid date values
            }
        }

        // Count total matching items at the SQL level (without LIMIT/OFFSET). No join is in
        // play at this point, so one row per trip and no DISTINCT to pay for.
        $countQb = clone $qb;
        $countQb->select('COUNT(t.id)')->resetDQLPart('orderBy');

        $totalItems = (int) $countQb->getQuery()->getSingleScalarResult();

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        /** @var list<TripRequest> $entities */
        $entities = $qb->getQuery()->getResult();

        $tripIds = array_map(static function (TripRequest $entity): string {
            \assert($entity->id instanceof Uuid);

            return $entity->id->toRfc4122();
        }, $entities);

        $statusesByTripId = $this->computationTracker->getStatusesBatch($tripIds);
        $totalsByTripId = $this->stageTotals($entities);

        $items = array_map(function (TripRequest $entity) use ($statusesByTripId, $totalsByTripId): TripListItem {
            \assert($entity->id instanceof Uuid);
            $id = $entity->id->toRfc4122();

            return $this->toListItem($entity, $statusesByTripId[$id] ?? null, $totalsByTripId[$id] ?? [0.0, 0]);
        }, $entities);

        return new TripListPaginator($items, $page, $limit, $totalItems);
    }

    /**
     * Ridden distance and stage count per trip, read as an aggregate.
     *
     * The page used to be fetch-joined with its stages so these two numbers could be summed
     * in PHP, which hydrated eight JSONB columns per stage — geometry included — to produce
     * a float and an int. One grouped scalar query instead, on the same identifiers the
     * status lookup above already batches.
     *
     * Rest days are excluded here rather than in a join condition, and the query is separate
     * rather than a GROUP BY on the page: a left join carrying that predicate in its WHERE
     * turns into an inner join, and a trip with no stages — the state every new trip is in —
     * would drop out of the list entirely. Trips missing from this map fall back to zero.
     *
     * @param list<TripRequest> $entities
     *
     * @return array<string, array{float, int}>
     */
    private function stageTotals(array $entities): array
    {
        if ([] === $entities) {
            return [];
        }

        /** @var list<array{tripId: string, distance: numeric-string|float|null, stages: int}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT IDENTITY(s.trip) AS tripId, SUM(s.distance) AS distance, COUNT(s.id) AS stages
             FROM App\Entity\Stage s
             WHERE s.trip IN (:tripIds) AND s.isRestDay = false
             GROUP BY s.trip',
        )
            ->setParameter('tripIds', array_column($entities, 'id'))
            ->getResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[Uuid::fromString($row['tripId'])->toRfc4122()] = [(float) $row['distance'], (int) $row['stages']];
        }

        return $totals;
    }

    /**
     * @param array<string, string>|null $computationStatuses
     * @param array{float, int}          $totals              ridden distance and stage count, rest days excluded
     */
    private function toListItem(TripRequest $entity, ?array $computationStatuses, array $totals): TripListItem
    {
        \assert($entity->id instanceof Uuid);

        [$totalDistance, $stageCount] = $totals;

        return new TripListItem(
            id: $entity->id->toRfc4122(),
            title: $entity->title,
            startDate: $entity->startDate,
            endDate: $entity->endDate,
            totalDistance: $totalDistance,
            stageCount: $stageCount,
            createdAt: $entity->createdAt,
            updatedAt: $entity->updatedAt,
            status: $this->computeStatus($computationStatuses, $stageCount),
        );
    }

    /**
     * Derives the trip status from the computation tracker data and stage count.
     *
     * - "draft"     : no computations tracked yet, or none succeeded and no stage was
     *                 persisted — nothing to show, so the user can start over
     * - "analyzing" : at least one computation is still pending or running
     * - "analyzed"  : results are available (≥1 `done`, or stages from a superseded run)
     * - "failed"    : every computation failed, but stages exist from an earlier run
     *
     * The map itself is now durable past the cache's 30-minute TTL
     * ({@see \App\ComputationTracker\PersistingComputationTracker}), so the fallback on
     * `$stageCount` below only catches a trip that never had a computation tracked at all —
     * not, as before, every trip older than half an hour.
     *
     * @param array<string, string>|null $statuses
     */
    private function computeStatus(?array $statuses, int $stageCount): string
    {
        if (null === $statuses || [] === $statuses) {
            return $stageCount > 0 ? 'analyzed' : 'draft';
        }

        $hasDone = false;
        $hasFailed = false;
        foreach ($statuses as $status) {
            if (!ComputationStatus::tryFrom($status)?->isSettled()) {
                return 'analyzing';
            }

            if (ComputationStatus::DONE->value === $status) {
                $hasDone = true;
            } elseif (ComputationStatus::FAILED->value === $status) {
                $hasFailed = true;
            }
        }

        // Terminal state: if nothing ever succeeded (all failed) and no stages
        // were persisted, the analysis effectively did not happen → draft,
        // so the user can retry without the list being stuck on "analyzed".
        if (!$hasDone && 0 === $stageCount) {
            return 'draft';
        }

        // Nothing succeeded, but stages exist. This used to answer 'analyzed', so a trip
        // whose every computation had failed was indistinguishable in the list from one
        // that had worked (ADR-072).
        if ($hasFailed && !$hasDone) {
            return 'failed';
        }

        // A *partial* failure never reaches here: $hasDone is true, so the trip is usable
        // and reads 'analyzed'. Only a total failure is called out.

        // `superseded` lands here, and deliberately keeps its own answer rather than gaining
        // one: a trip whose computations were abandoned when it moved still has the stages
        // the previous generation produced, so it reads 'analyzed'. It is not `failed` —
        // nothing broke — and not `analyzing` — nothing is running (ADR-073).
        return 'analyzed';
    }
}
