<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TripListItem;
use App\ApiResource\TripRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Provides a paginated, filterable list of trips from PostgreSQL.
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
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return ArrayPaginator<TripListItem>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ArrayPaginator
    {
        $filters = $context['filters'] ?? [];

        [$page, , $limit] = $this->pagination->getPagination($operation, $context);

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t')
            ->from(TripRequest::class, 't')
            ->orderBy('t.createdAt', 'DESC');

        // Filter by title (partial, case-insensitive)
        if (!empty($filters['title'])) {
            $qb->andWhere('LOWER(t.title) LIKE LOWER(:title)')
                ->setParameter('title', '%'.(string) $filters['title'].'%');
        }

        // Filter by startDate (trips starting on or after this date)
        if (!empty($filters['startDate'])) {
            try {
                $start = new \DateTimeImmutable((string) $filters['startDate']);
                $qb->andWhere('t.startDate >= :startDate')
                    ->setParameter('startDate', $start);
            } catch (\Exception) {
                // Ignore invalid date values
            }
        }

        // Filter by endDate (trips ending on or before this date)
        if (!empty($filters['endDate'])) {
            try {
                $end = new \DateTimeImmutable((string) $filters['endDate']);
                $qb->andWhere('t.endDate <= :endDate')
                    ->setParameter('endDate', $end);
            } catch (\Exception) {
                // Ignore invalid date values
            }
        }

        // Fetch all matching entities (without pagination) so that ArrayPaginator
        // can compute the correct total item count from the full dataset.
        /** @var list<TripRequest> $entities */
        $entities = $qb->getQuery()->getResult();

        $items = array_map([$this, 'toListItem'], $entities);

        $firstResult = ($page - 1) * $limit;

        return new ArrayPaginator($items, $firstResult, $limit);
    }

    private function toListItem(TripRequest $entity): TripListItem
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
        );
    }
}
