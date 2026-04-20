<?php

declare(strict_types=1);

namespace App\State;

use Doctrine\ORM\Tools\Pagination\Paginator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripListItem;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
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
        $rawFilters = $context['filters'] ?? [];
        /** @var array<string, mixed> $filters */
        $filters = is_array($rawFilters) ? $rawFilters : [];

        [$page, , $limit] = $this->pagination->getPagination($operation, $context);

        $user = $this->security->getUser();

        \assert($user instanceof User);

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t')
            ->from(TripRequest::class, 't')
            ->orderBy('t.createdAt', 'DESC')
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

        // Count total matching items at the SQL level (without LIMIT/OFFSET).
        $countQb = clone $qb;
        $countQb->select('COUNT(DISTINCT t.id)')->resetDQLPart('orderBy');

        $totalItems = (int) $countQb->getQuery()->getSingleScalarResult();

        // Fetch only the current page using SQL LIMIT/OFFSET, and JOIN FETCH
        // stages to avoid N+1 queries when computing totals in toListItem().
        // Use Doctrine Paginator with fetchJoinCollection=true so that
        // setMaxResults limits *entities*, not SQL rows (fetch-join multiplies rows).
        $qb->leftJoin('t.stages', 's')
            ->addSelect('s')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: true);
        /** @var list<TripRequest> $entities */
        $entities = iterator_to_array($paginator->getIterator());

        $tripIds = array_map(static function (TripRequest $entity): string {
            \assert($entity->id instanceof Uuid);

            return $entity->id->toRfc4122();
        }, $entities);

        $statusesByTripId = $this->computationTracker->getStatusesBatch($tripIds);

        $items = array_map(function (TripRequest $entity) use ($statusesByTripId): TripListItem {
            \assert($entity->id instanceof Uuid);

            return $this->toListItem($entity, $statusesByTripId[$entity->id->toRfc4122()] ?? null);
        }, $entities);

        return new TripListPaginator($items, $page, $limit, $totalItems);
    }

    /**
     * @param array<string, string>|null $computationStatuses
     */
    private function toListItem(TripRequest $entity, ?array $computationStatuses): TripListItem
    {
        \assert($entity->id instanceof Uuid);

        // Compute total distance and stage count from persistent stages
        $totalDistance = 0.0;
        $stageCount = 0;
        foreach ($entity->stages as $stage) {
            if (!$stage->isRestDay()) {
                $totalDistance += $stage->getDistance();
                ++$stageCount;
            }
        }

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
     * - "draft"     : no computations tracked yet, or every tracked computation
     *                failed without ever producing a `done` state
     * - "analyzing" : at least one computation is still pending or running
     * - "analyzed"  : at least one computation reached `done` (results available)
     *
     * The computation-tracking cache has a 30-minute TTL, so a fully analyzed
     * trip eventually loses its `$statuses` map. In that case we fall back to
     * the durable `$stageCount` (persisted in PostgreSQL) so the trip keeps
     * reporting "analyzed" instead of reverting to "draft".
     *
     * @param array<string, string>|null $statuses
     */
    private function computeStatus(?array $statuses, int $stageCount): string
    {
        if (null === $statuses || [] === $statuses) {
            return $stageCount > 0 ? 'analyzed' : 'draft';
        }

        $hasDone = false;
        foreach ($statuses as $status) {
            if ('pending' === $status || 'running' === $status) {
                return 'analyzing';
            }

            if ('done' === $status) {
                $hasDone = true;
            }
        }

        // Terminal state: if nothing ever succeeded (all failed) and no stages
        // were persisted, the analysis effectively did not happen → draft,
        // so the user can retry without the list being stuck on "analyzed".
        if (!$hasDone && 0 === $stageCount) {
            return 'draft';
        }

        return 'analyzed';
    }
}
