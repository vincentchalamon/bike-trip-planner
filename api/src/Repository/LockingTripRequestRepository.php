<?php

declare(strict_types=1);

namespace App\Repository;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Event;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Enum\AlertGroup;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Serialises every write to a trip's stage collection.
 *
 * Two write shapes race with each other: the HTTP processors read the whole collection,
 * mutate it in memory and write it back, while the enrichment workers write one column of
 * one stage. Left alone, a worker's write lands between a processor's read and its write
 * and is silently reverted — the "weather disappears" bug (recette #649), which the
 * targeted writes only narrowed rather than closed.
 *
 * Both shapes take the same per-trip lock here, so they interleave instead of overlapping.
 * Decorating the interface rather than one implementation means the Redis-backed
 * implementation used by the functional suite is covered by the same logic as the Doctrine
 * one used in production.
 */
#[AsDecorator(decorates: TripRequestRepositoryInterface::class)]
final class LockingTripRequestRepository implements TripRequestRepositoryInterface
{
    /**
     * Long enough to cover a storeStages() that recomputes the two PostGIS scans, which
     * take seconds on a long route. The 5s used for a plain Redis read-modify-write would
     * expire mid-write and let a second writer in, corrupting the collection with no signal.
     */
    private const int LOCK_TTL = 30;

    /** How long a writer waits for the lock before giving up, in microseconds. */
    private const int ACQUIRE_TIMEOUT_US = 3_000_000;

    private const int ACQUIRE_RETRY_US = 25_000;

    /**
     * Locks held by this process, with their nesting depth.
     *
     * Re-entrance has to be tracked explicitly: createLock() mints a fresh token on every
     * call, so a nested blocking acquire of the same key would wait on a lock this very
     * process holds and never return. mutateStages() calling storeStages() is exactly
     * that case.
     *
     * @var array<string, array{lock: SharedLockInterface, depth: int}>
     */
    private array $held = [];

    public function __construct(
        private readonly TripRequestRepositoryInterface $decorated,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * @param callable(list<Stage>): list<Stage> $mutator
     */
    public function mutateStages(string $tripId, callable $mutator, ?int $expectedVersion = null): ?StageWriteResult
    {
        return $this->withStagesLock($tripId, fn (): ?StageWriteResult => $this->decorated->mutateStages($tripId, $mutator, $expectedVersion));
    }

    /** @param list<Stage> $stages */
    public function storeStages(string $tripId, array $stages): void
    {
        $this->withStagesLock($tripId, function () use ($tripId, $stages): void {
            $this->decorated->storeStages($tripId, $stages);
        });
    }

    public function updateStageWeather(string $tripId, string $stageId, ?WeatherForecast $weather): void
    {
        $this->withStagesLock($tripId, function () use ($tripId, $stageId, $weather): void {
            $this->decorated->updateStageWeather($tripId, $stageId, $weather);
        });
    }

    /**
     * Straight through, with no lock — unlike every other write in this class.
     *
     * The group writes are not read-modify-write sequences: each is a single UPDATE whose
     * merge Postgres performs, so two producers finishing at once cannot lose each other's
     * work (ADR-068). Taking the per-trip lock would instead serialise a dozen handlers that
     * run in parallel by design, behind a 3-second bounded acquire that turns a burst into
     * failed computations.
     *
     * @param list<array<string, mixed>> $alerts
     */
    public function updateStageAlertsForGroup(string $tripId, string $stageId, AlertGroup $group, array $alerts): void
    {
        $this->decorated->updateStageAlertsForGroup($tripId, $stageId, $group, $alerts);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $alertsByStageId
     *
     * @see self::updateStageAlertsForGroup() for why this takes no lock
     */
    public function updateTripAlertsForGroup(string $tripId, AlertGroup $group, array $alertsByStageId): void
    {
        $this->decorated->updateTripAlertsForGroup($tripId, $group, $alertsByStageId);
    }

    /** @param list<Event> $events */
    public function updateStageEvents(string $tripId, string $stageId, array $events): void
    {
        $this->withStagesLock($tripId, function () use ($tripId, $stageId, $events): void {
            $this->decorated->updateStageEvents($tripId, $stageId, $events);
        });
    }

    /** @param list<array<string, mixed>> $markers */
    public function updateStageSupplyTimeline(string $tripId, string $stageId, array $markers): void
    {
        $this->withStagesLock($tripId, function () use ($tripId, $stageId, $markers): void {
            $this->decorated->updateStageSupplyTimeline($tripId, $stageId, $markers);
        });
    }

    public function updateStageResupply(string $tripId, string $stageId, Resupply $resupply): void
    {
        $this->withStagesLock($tripId, function () use ($tripId, $stageId, $resupply): void {
            $this->decorated->updateStageResupply($tripId, $stageId, $resupply);
        });
    }

    /** @param list<Accommodation> $accommodations */
    public function updateStageAccommodations(string $tripId, string $stageId, array $accommodations): void
    {
        $this->withStagesLock($tripId, function () use ($tripId, $stageId, $accommodations): void {
            $this->decorated->updateStageAccommodations($tripId, $stageId, $accommodations);
        });
    }

    public function updateStageLabels(string $tripId, string $stageId, ?string $startLabel, ?string $endLabel): void
    {
        $this->withStagesLock($tripId, function () use ($tripId, $stageId, $startLabel, $endLabel): void {
            $this->decorated->updateStageLabels($tripId, $stageId, $startLabel, $endLabel);
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    private function withStagesLock(string $tripId, callable $write): mixed
    {
        if (isset($this->held[$tripId])) {
            ++$this->held[$tripId]['depth'];

            try {
                return $write();
            } finally {
                --$this->held[$tripId]['depth'];
            }
        }

        $lock = $this->lockFactory->createLock(\sprintf('trip.%s.stages.update', $tripId), self::LOCK_TTL);
        $this->acquireWithinTimeout($lock, $tripId);
        $this->held[$tripId] = ['lock' => $lock, 'depth' => 1];

        try {
            return $write();
        } finally {
            unset($this->held[$tripId]);
            $lock->release();
        }
    }

    /**
     * Never a blocking acquire: on the HTTP path that would tie up a PHP-FPM worker for as
     * long as the holder runs, so a slow write would degrade into an outage rather than a
     * refusal. Bounded retries, then a 409 the caller can act on.
     */
    private function acquireWithinTimeout(SharedLockInterface $lock, string $tripId): void
    {
        $deadline = microtime(true) + self::ACQUIRE_TIMEOUT_US / 1_000_000;

        do {
            if ($lock->acquire()) {
                return;
            }

            usleep(self::ACQUIRE_RETRY_US);
        } while (microtime(true) < $deadline);

        throw new ConflictHttpException(\sprintf('The stages of trip %s are being written by another request; retry.', $tripId));
    }

    // Everything below carries no stage-collection write, so it passes straight through.

    public function initializeTrip(string $tripId, TripRequest $request): void
    {
        $this->decorated->initializeTrip($tripId, $request);
    }

    public function getRequest(string $tripId): ?TripRequest
    {
        return $this->decorated->getRequest($tripId);
    }

    public function storeRequest(string $tripId, TripRequest $request): void
    {
        $this->decorated->storeRequest($tripId, $request);
    }

    public function getTitle(string $tripId): ?string
    {
        return $this->decorated->getTitle($tripId);
    }

    public function storeTitle(string $tripId, ?string $title): void
    {
        $this->decorated->storeTitle($tripId, $title);
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $rawPoints */
    public function storeRawPoints(string $tripId, array $rawPoints): void
    {
        $this->decorated->storeRawPoints($tripId, $rawPoints);
    }

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getRawPoints(string $tripId): ?array
    {
        return $this->decorated->getRawPoints($tripId);
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $decimatedPoints */
    public function storeDecimatedPoints(string $tripId, array $decimatedPoints): void
    {
        $this->decorated->storeDecimatedPoints($tripId, $decimatedPoints);
    }

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getDecimatedPoints(string $tripId): ?array
    {
        return $this->decorated->getDecimatedPoints($tripId);
    }

    /** @return list<Stage>|null */
    public function getStages(string $tripId): ?array
    {
        return $this->decorated->getStages($tripId);
    }

    /** @return list<array{lat: float, lon: float}>|null */
    public function getStageGeometry(string $tripId, string $stageId): ?array
    {
        return $this->decorated->getStageGeometry($tripId, $stageId);
    }

    public function getStageIdByDayNumber(string $tripId, int $dayNumber): ?string
    {
        return $this->decorated->getStageIdByDayNumber($tripId, $dayNumber);
    }

    public function getVersion(string $tripId): ?int
    {
        return $this->decorated->getVersion($tripId);
    }

    /**
     * Under the same lock as the stage writes: the version is bumped by those writes too,
     * so a bare read-modify-write here could interleave with one and lose a bump. That lock
     * is also what makes the `If-Match` comparison sound — the decorated implementation
     * compares and increments without anything able to slip between the two.
     */
    public function bumpVersion(string $tripId, ?int $expectedVersion = null): int
    {
        return $this->withStagesLock($tripId, fn (): int => $this->decorated->bumpVersion($tripId, $expectedVersion));
    }

    /** @param list<list<array{lat: float, lon: float, ele: float}>> $tracksData */
    public function storeTracksData(string $tripId, array $tracksData): void
    {
        $this->decorated->storeTracksData($tripId, $tracksData);
    }

    /** @return list<list<array{lat: float, lon: float, ele: float}>>|null */
    public function getTracksData(string $tripId): ?array
    {
        return $this->decorated->getTracksData($tripId);
    }

    public function storeSourceType(string $tripId, string $sourceType): void
    {
        $this->decorated->storeSourceType($tripId, $sourceType);
    }

    public function getSourceType(string $tripId): ?string
    {
        return $this->decorated->getSourceType($tripId);
    }

    public function storeStatus(string $tripId, string $status): void
    {
        $this->decorated->storeStatus($tripId, $status);
    }

    public function storeLocale(string $tripId, string $locale): void
    {
        $this->decorated->storeLocale($tripId, $locale);
    }

    public function getLocale(string $tripId): ?string
    {
        return $this->decorated->getLocale($tripId);
    }

    public function getOwnerId(string $tripId): ?string
    {
        return $this->decorated->getOwnerId($tripId);
    }
}
