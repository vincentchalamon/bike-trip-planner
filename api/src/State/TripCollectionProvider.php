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
use App\Repository\OwnedTripFinderInterface;
use App\State\Mcp\McpArguments;
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
        private OwnedTripFinderInterface $trips,
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

        $title = isset($filters['title']) && \is_string($filters['title']) ? $filters['title'] : null;
        $startsFrom = $this->date($filters['startDate'] ?? null);
        $endsBy = $this->date($filters['endDate'] ?? null);

        $totalItems = $this->trips->countOwnedBy($user, $title, $startsFrom, $endsBy);
        $entities = $this->trips->findPageOwnedBy($user, $title, $startsFrom, $endsBy, ($page - 1) * $limit, $limit);

        $uuids = array_map(static function (TripRequest $entity): Uuid {
            \assert($entity->id instanceof Uuid);

            return $entity->id;
        }, $entities);

        $statusesByTripId = $this->computationTracker->getStatusesBatch(array_map(static fn (Uuid $id): string => $id->toRfc4122(), $uuids));
        // A trip with no ridden stage yet is absent from the map and falls back to zero.
        $totalsByTripId = $this->trips->stageTotalsByTrip($uuids);

        $items = array_map(function (TripRequest $entity) use ($statusesByTripId, $totalsByTripId): TripListItem {
            \assert($entity->id instanceof Uuid);
            $id = $entity->id->toRfc4122();

            return $this->toListItem($entity, $statusesByTripId[$id] ?? null, $totalsByTripId[$id] ?? [0.0, 0]);
        }, $entities);

        return new TripListPaginator($items, $page, $limit, $totalItems);
    }

    /**
     * An unparseable date filters nothing rather than failing the list.
     */
    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (empty($value) || !\is_string($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
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
