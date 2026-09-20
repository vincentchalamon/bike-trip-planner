<?php

declare(strict_types=1);

namespace App\Repository;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\Event;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Enum\AlertGroup;
use App\Concurrency\VersionPrecondition;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RedisTripRequestRepository implements TripRequestRepositoryInterface
{
    private const int TTL = 1800; // 30 minutes

    public function __construct(
        #[Autowire(service: 'cache.trip_state')]
        private CacheItemPoolInterface $tripStateCache,
    ) {
    }

    public function initializeTrip(string $tripId, TripRequest $request): void
    {
        $this->set($this->requestKey($tripId), $request);
    }

    public function getRequest(string $tripId): ?TripRequest
    {
        /** @var TripRequest|null $value */
        $value = $this->get($this->requestKey($tripId));

        return $value;
    }

    public function storeRequest(string $tripId, TripRequest $request): void
    {
        $this->set($this->requestKey($tripId), $request);
    }

    public function getTitle(string $tripId): ?string
    {
        /** @var string|null $value */
        $value = $this->get($this->titleKey($tripId));

        return $value;
    }

    public function storeTitle(string $tripId, ?string $title): void
    {
        $this->set($this->titleKey($tripId), $title);
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $rawPoints */
    public function storeRawPoints(string $tripId, array $rawPoints): void
    {
        $this->set($this->rawPointsKey($tripId), $rawPoints);
    }

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getRawPoints(string $tripId): ?array
    {
        /** @var list<array{lat: float, lon: float, ele: float}>|null $value */
        $value = $this->get($this->rawPointsKey($tripId));

        return $value;
    }

    /** @param list<array{lat: float, lon: float, ele: float}> $decimatedPoints */
    public function storeDecimatedPoints(string $tripId, array $decimatedPoints): void
    {
        $this->set($this->decimatedPointsKey($tripId), $decimatedPoints);
    }

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getDecimatedPoints(string $tripId): ?array
    {
        /** @var list<array{lat: float, lon: float, ele: float}>|null $value */
        $value = $this->get($this->decimatedPointsKey($tripId));

        return $value;
    }

    /** @param list<Stage> $stages */
    public function storeStages(string $tripId, array $stages): void
    {
        $this->set($this->stagesKey($tripId), $this->keepingEnrichment($tripId, $stages));
        // Any write of the collection is a structural change (see TripRequest::$version).
        $this->bumpVersion($tripId);
    }

    /**
     * Carries the enrichment columns over from what is stored, instead of taking them from
     * the incoming DTOs.
     *
     * The Doctrine implementation gets this for free — `applyDtoToEntity()` simply never
     * touches those columns (ADR-068). Here the whole collection *is* the storage unit, so
     * the partition has to be performed by hand, or a structural edit would replay whatever
     * snapshot the processor read over a producer's write. The contract suite is what caught
     * the two implementations disagreeing.
     *
     * @param list<Stage> $stages
     *
     * @return list<Stage>
     */
    private function keepingEnrichment(string $tripId, array $stages): array
    {
        $stored = [];
        foreach ($this->getStages($tripId) ?? [] as $existing) {
            $stored[$existing->id] = $existing;
        }

        foreach ($stages as $stage) {
            $existing = $stored[$stage->id] ?? null;
            if (!$existing instanceof Stage) {
                continue;
            }

            $stage->alertsByGroup = $existing->alertsByGroup;
            $stage->events = $existing->events;
            $stage->supplyTimeline = $existing->supplyTimeline;
        }

        return $stages;
    }

    /**
     * Writes the blob without touching the version.
     *
     * A targeted enrichment write has to go through the whole blob here — it is the
     * storage unit — but it is not a structural change, and bumping the version would
     * make a client's ETag go stale on its own while enrichments land.
     *
     * @param list<Stage> $stages
     */
    private function storeStagesWithoutVersionBump(string $tripId, array $stages): void
    {
        $this->set($this->stagesKey($tripId), $stages);
    }

    /** @return list<Stage>|null */
    public function getStages(string $tripId): ?array
    {
        /** @var list<Stage>|null $value */
        $value = $this->get($this->stagesKey($tripId));

        return $value;
    }

    /** @return list<array{lat: float, lon: float}>|null */
    public function getStageGeometry(string $tripId, string $stageId): ?array
    {
        $stages = $this->getStages($tripId);
        if (null === $stages) {
            return null;
        }

        foreach ($stages as $stage) {
            if ($stage->id !== $stageId) {
                continue;
            }

            if ([] === $stage->geometry) {
                return null;
            }

            return array_map(
                static fn (Coordinate $point): array => ['lat' => $point->lat, 'lon' => $point->lon],
                $stage->geometry,
            );
        }

        return null;
    }

    /**
     * @param callable(list<Stage>): list<Stage> $mutator
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

    public function getVersion(string $tripId): ?int
    {
        $value = $this->get($this->versionKey($tripId));

        return \is_int($value) ? $value : null;
    }

    public function bumpVersion(string $tripId, ?int $expectedVersion = null): int
    {
        $current = $this->getVersion($tripId);
        VersionPrecondition::assert($expectedVersion, $current, $tripId);

        $next = ($current ?? 1) + 1;
        $this->set($this->versionKey($tripId), $next);

        return $next;
    }

    public function getStageIdByDayNumber(string $tripId, int $dayNumber): ?string
    {
        foreach ($this->getStages($tripId) ?? [] as $stage) {
            if ($stage->dayNumber === $dayNumber) {
                return $stage->id;
            }
        }

        return null;
    }

    public function updateStageWeather(string $tripId, string $stageId, ?WeatherForecast $weather): void
    {
        $this->updateStageField($tripId, $stageId, static function (Stage $stage) use ($weather): void {
            $stage->weather = $weather;
        });
    }

    /** @param list<array<string, mixed>> $alerts */
    public function updateStageAlertsForGroup(string $tripId, string $stageId, AlertGroup $group, array $alerts): void
    {
        $this->updateStageField($tripId, $stageId, static function (Stage $stage) use ($group, $alerts): void {
            $stage->setAlertsForGroup($group, $alerts);
        });
    }

    /** @param array<string, list<array<string, mixed>>> $alertsByStageId */
    public function updateTripAlertsForGroup(string $tripId, AlertGroup $group, array $alertsByStageId): void
    {
        // Every stage, not only those carrying alerts: one that dropped out of the new set
        // has to lose the group rather than keep a stale entry.
        foreach ($this->getStages($tripId) ?? [] as $stage) {
            $this->updateStageAlertsForGroup($tripId, $stage->id, $group, $alertsByStageId[$stage->id] ?? []);
        }
    }

    /** @param list<Event> $events */
    public function updateStageEvents(string $tripId, string $stageId, array $events): void
    {
        $this->updateStageField($tripId, $stageId, static function (Stage $stage) use ($events): void {
            $stage->events = $events;
        });
    }

    /** @param list<array<string, mixed>> $markers */
    public function updateStageSupplyTimeline(string $tripId, string $stageId, array $markers): void
    {
        $this->updateStageField($tripId, $stageId, static function (Stage $stage) use ($markers): void {
            $stage->supplyTimeline = $markers;
        });
    }

    public function updateStageResupply(string $tripId, string $stageId, Resupply $resupply): void
    {
        $this->updateStageField($tripId, $stageId, static function (Stage $stage) use ($resupply): void {
            $stage->resupply = $resupply;
        });
    }

    /** @param list<Accommodation> $accommodations */
    public function updateStageAccommodations(string $tripId, string $stageId, array $accommodations): void
    {
        $this->updateStageField($tripId, $stageId, static function (Stage $stage) use ($accommodations): void {
            $stage->accommodations = $accommodations;
        });
    }

    public function updateStageLabels(string $tripId, string $stageId, ?string $startLabel, ?string $endLabel): void
    {
        $this->updateStageField($tripId, $stageId, static function (Stage $stage) use ($startLabel, $endLabel): void {
            $stage->startLabel = $startLabel;
            $stage->endLabel = $endLabel;
        });
    }

    /**
     * Read-modify-write of a single stage (matched by identifier) in the monolithic blob.
     *
     * No lock of its own: {@see LockingTripRequestRepository} already holds the per-trip
     * one around every entry point here. Taking it again would be worse than redundant —
     * createLock() mints a fresh token per call, so the nested blocking acquire would wait
     * on the lock this very process holds and never return.
     *
     * @param callable(Stage): void $mutator
     */
    private function updateStageField(string $tripId, string $stageId, callable $mutator): void
    {
        $stages = $this->getStages($tripId);
        if (null === $stages) {
            return;
        }

        $changed = false;
        foreach ($stages as $stage) {
            if ($stage->id === $stageId) {
                $mutator($stage);
                $changed = true;
                break;
            }
        }

        if ($changed) {
            $this->storeStagesWithoutVersionBump($tripId, $stages);
        }
    }

    /**
     * Stores multi-track data for Komoot Collection source type.
     *
     * @param list<list<array{lat: float, lon: float, ele: float}>> $tracksData
     */
    public function storeTracksData(string $tripId, array $tracksData): void
    {
        $this->set($this->tracksDataKey($tripId), $tracksData);
    }

    /**
     * @return list<list<array{lat: float, lon: float, ele: float}>>|null
     */
    public function getTracksData(string $tripId): ?array
    {
        /** @var list<list<array{lat: float, lon: float, ele: float}>>|null $value */
        $value = $this->get($this->tracksDataKey($tripId));

        return $value;
    }

    public function storeSourceType(string $tripId, string $sourceType): void
    {
        $this->set($this->sourceTypeKey($tripId), $sourceType);
    }

    public function getSourceType(string $tripId): ?string
    {
        /** @var string|null $value */
        $value = $this->get($this->sourceTypeKey($tripId));

        return $value;
    }

    public function storeStatus(string $tripId, string $status): void
    {
        $request = $this->getRequest($tripId);
        if (!$request instanceof TripRequest) {
            return;
        }

        $request->status = $status;
        $this->set($this->requestKey($tripId), $request);
    }

    private function set(string $key, mixed $value): void
    {
        $item = $this->tripStateCache->getItem($key);
        $item->set($value);
        $item->expiresAfter(self::TTL);

        $this->tripStateCache->save($item);
    }

    private function get(string $key): mixed
    {
        $item = $this->tripStateCache->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        // Refresh TTL on access
        $item->expiresAfter(self::TTL);
        $this->tripStateCache->save($item);

        return $item->get();
    }

    private function requestKey(string $tripId): string
    {
        return \sprintf('trip.%s.request', $tripId);
    }

    private function rawPointsKey(string $tripId): string
    {
        return \sprintf('trip.%s.raw_points', $tripId);
    }

    private function decimatedPointsKey(string $tripId): string
    {
        return \sprintf('trip.%s.decimated_points', $tripId);
    }

    private function versionKey(string $tripId): string
    {
        return \sprintf('trip.%s.version', $tripId);
    }

    private function stagesKey(string $tripId): string
    {
        return \sprintf('trip.%s.stages', $tripId);
    }

    private function sourceTypeKey(string $tripId): string
    {
        return \sprintf('trip.%s.source_type', $tripId);
    }

    private function tracksDataKey(string $tripId): string
    {
        return \sprintf('trip.%s.tracks_data', $tripId);
    }

    public function storeLocale(string $tripId, string $locale): void
    {
        $this->set($this->localeKey($tripId), $locale);
    }

    public function getLocale(string $tripId): ?string
    {
        /** @var string|null $value */
        $value = $this->get($this->localeKey($tripId));

        return $value;
    }

    public function getOwnerId(string $tripId): ?string
    {
        return $this->getRequest($tripId)?->user?->getId()->toRfc4122();
    }

    private function titleKey(string $tripId): string
    {
        return \sprintf('trip.%s.title', $tripId);
    }

    private function localeKey(string $tripId): string
    {
        return \sprintf('trip.%s.locale', $tripId);
    }
}
