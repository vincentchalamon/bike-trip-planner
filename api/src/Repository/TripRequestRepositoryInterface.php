<?php

declare(strict_types=1);

namespace App\Repository;

use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Event;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Enum\AlertGroup;

/**
 * Repository for the trip computation state aggregate.
 *
 * Stores and retrieves all data produced during the async computation pipeline
 * (request parameters, parsed route points, generated stages, multi-track data…).
 * The underlying storage is expected to be a short-lived key-value store (TTL ~30 min).
 */
interface TripRequestRepositoryInterface
{
    public function initializeTrip(string $tripId, TripRequest $request): void;

    public function getRequest(string $tripId): ?TripRequest;

    public function storeRequest(string $tripId, TripRequest $request): void;

    public function getTitle(string $tripId): ?string;

    public function storeTitle(string $tripId, ?string $title): void;

    /** @param list<array{lat: float, lon: float, ele: float}> $rawPoints */
    public function storeRawPoints(string $tripId, array $rawPoints): void;

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getRawPoints(string $tripId): ?array;

    /** @param list<array{lat: float, lon: float, ele: float}> $decimatedPoints */
    public function storeDecimatedPoints(string $tripId, array $decimatedPoints): void;

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getDecimatedPoints(string $tripId): ?array;

    /** @param list<Stage> $stages */
    public function storeStages(string $tripId, array $stages): void;

    /** @return list<Stage>|null */
    public function getStages(string $tripId): ?array;

    /**
     * Reads the stages, applies the mutation, writes them back — as one atomic unit.
     *
     * Every caller that edits the stage collection (create, update, move, delete, rest
     * day, accommodation selection) does exactly this sequence. Done by hand it is a
     * read-modify-write with no protection: a worker writing one enrichment column in
     * between has its write silently reverted by the caller's stale snapshot. Routed
     * through here, the whole sequence is serialised against the targeted writes by
     * {@see LockingTripRequestRepository}.
     *
     * The mutator returns the new list rather than mutating by reference, because the
     * callers splice, reorder and renumber.
     *
     * Hands back the resulting version along with the stages, read while the write is still
     * serialised — see {@see StageWriteResult} for why reading it afterwards is not the same
     * thing.
     *
     * $expectedVersion carries the client's `If-Match` precondition. It is compared here,
     * inside the critical section, rather than by the caller: checked earlier the comparison
     * would be a TOCTOU as wide as the processor body (see {@see \App\Concurrency\VersionPrecondition}).
     *
     * @param callable(list<Stage>): list<Stage> $mutator
     *
     * @return StageWriteResult|null null when the trip is unknown
     *
     * @throws PreconditionFailedHttpException when $expectedVersion is stale
     */
    public function mutateStages(string $tripId, callable $mutator, ?int $expectedVersion = null): ?StageWriteResult;

    /**
     * Returns a single stage's route geometry, in travel order, projected to 2D.
     *
     * Feeds the in-ride detour calculation ({@see \App\InRide\DetourCalculator}),
     * which is planar; `ele` is intentionally dropped. A read of only the geometry,
     * not the whole aggregate ({@see self::getStages()} hydrates weather, POIs,
     * accommodations…).
     *
     * @return list<array{lat: float, lon: float}>|null null when the trip, the stage,
     *                                                  or the geometry does not exist
     */
    public function getStageGeometry(string $tripId, string $stageId): ?array;

    /**
     * Returns every stage's day number and geometry, in travel order.
     *
     * What the map needs and nothing else. Not {@see self::getStageGeometry()} widened: that
     * one is planar and drops `ele`. Not {@see self::getStages()} narrowed either — that
     * hydrates weather, alerts, events, the supply timeline and the accommodations of every
     * stage for a response that keeps two fields.
     *
     * An unknown trip and a trip with no stage both answer `[]`; callers that need to tell
     * them apart read {@see self::getVersion()}, which is null only for the former.
     *
     * @return list<array{dayNumber: int, geometry: list<array{lat: float, lon: float, ele: float}>}>
     */
    public function getRouteGeometry(string $tripId): array;

    /**
     * Resolves a day number to the stage identifier, for the one caller that still
     * addresses a stage by day: the `stageDay` field of the in-ride nearby-POI search
     * request, which is part of the public request body.
     *
     * A scalar lookup, so the detour path keeps reading no more than it needs.
     */
    public function getStageIdByDayNumber(string $tripId, int $dayNumber): ?string;

    /**
     * The trip's structural version — see {@see TripRequest::$version}.
     *
     * Bumped by {@see self::storeStages()} itself, so any write of the collection moves it,
     * including the ones a worker performs when the pacing is regenerated.
     */
    public function getVersion(string $tripId): ?int;

    /**
     * Bumps the structural version without writing stages, for a change that invalidates
     * in-flight computations without rewriting the collection (trip settings, batch
     * recompute).
     *
     * @param int|null $expectedVersion the client's `If-Match` precondition, compared inside
     *                                  the critical section — see {@see self::mutateStages()}
     *
     * @return int the new version, or 0 when the trip is unknown
     *
     * @throws PreconditionFailedHttpException when $expectedVersion is stale
     */
    public function bumpVersion(string $tripId, ?int $expectedVersion = null): int;

    /**
     * Persists a single stage's weather atomically, keyed by the stage identifier.
     *
     * Parallel enrichment handlers each own one JSONB column; routing them through
     * {@see self::storeStages()} re-writes the whole stages collection, so a slow
     * handler reading a stale snapshot overwrites a sibling's freshly-written column
     * (the weather/accommodations "disappear" bug — recette #649).
     *
     * Keyed by identifier rather than by dayNumber: every structural edit renumbers the
     * day numbers (`$i + 1`), so a handler that computed its result before a move would
     * otherwise write it onto a geographically different stage — a silent corruption,
     * not a lost write (ADR-066).
     */
    public function updateStageWeather(string $tripId, string $stageId, ?WeatherForecast $weather): void;

    /**
     * Persists one producer's alerts for one stage, replacing that group and only that group.
     *
     * The alerts are stored as the producer built them for the wire, minus what belongs to
     * the row rather than the alert: `stageId` is the address, and `dayNumber` is renumbered
     * by every structural edit, so persisting it would recreate the drift ADR-066 removed —
     * it is derived from the owning stage at read time.
     *
     * The payloads are *not* normalised into {@see Alert}, which models neither `poiName`
     * nor `imageUrl`, `openingHours`, `estimatedPrice`, `wikidataId` or `distanceFromRoute`.
     * Same array in, same array out to both consumers: GET/SSE parity by construction.
     *
     * @param list<array<string, mixed>> $alerts
     */
    public function updateStageAlertsForGroup(string $tripId, string $stageId, AlertGroup $group, array $alerts): void;

    /**
     * Replaces one producer's alerts across the **whole trip**, stage by stage.
     *
     * Not a convenience over {@see self::updateStageAlertsForGroup()}: the calendar check
     * recomputes the set for every stage at once, and a stage that dropped out of the new set
     * must lose its nudge rather than keep a stale one — the "Sunday bug" the client mirrors
     * in `reconciliation.ts`. A per-stage loop cannot express "and clear everyone else".
     *
     * @param array<string, list<array<string, mixed>>> $alertsByStageId stages absent from the
     *                                                                   map have the group cleared
     */
    public function updateTripAlertsForGroup(string $tripId, AlertGroup $group, array $alertsByStageId): void;

    /**
     * Persists a stage's nearby events atomically (see {@see self::updateStageWeather()}).
     *
     * Typed, unlike the alerts above: {@see Event} already models every field the wire
     * payload carries, so nothing is lost by going through it.
     *
     * @param list<Event> $events
     */
    public function updateStageEvents(string $tripId, string $stageId, array $events): void;

    /**
     * Persists a stage's supply timeline atomically (see {@see self::updateStageWeather()}).
     *
     * @param list<array<string, mixed>> $markers
     */
    public function updateStageSupplyTimeline(string $tripId, string $stageId, array $markers): void;

    /**
     * Persists a single stage's curated resupply atomically (see {@see self::updateStageWeather()}).
     */
    public function updateStageResupply(string $tripId, string $stageId, Resupply $resupply): void;

    /**
     * Persists a single stage's accommodations atomically (see {@see self::updateStageWeather()}).
     *
     * @param list<Accommodation> $accommodations
     */
    public function updateStageAccommodations(string $tripId, string $stageId, array $accommodations): void;

    /**
     * Persists a single stage's reverse-geocoded endpoint labels atomically (see {@see self::updateStageWeather()}).
     */
    public function updateStageLabels(string $tripId, string $stageId, ?string $startLabel, ?string $endLabel): void;

    /**
     * Stores multi-track data for Komoot Collection source type.
     *
     * @param list<list<array{lat: float, lon: float, ele: float}>> $tracksData
     */
    public function storeTracksData(string $tripId, array $tracksData): void;

    /** @return list<list<array{lat: float, lon: float, ele: float}>>|null */
    public function getTracksData(string $tripId): ?array;

    public function storeSourceType(string $tripId, string $sourceType): void;

    public function getSourceType(string $tripId): ?string;

    /**
     * Persists the structural-readiness status of a trip (ADR-043), e.g. `draft` → `ready`.
     *
     * Returns silently if the trip does not exist anymore.
     */
    public function storeStatus(string $tripId, string $status): void;

    public function storeLocale(string $tripId, string $locale): void;

    public function getLocale(string $tripId): ?string;

    /**
     * The RFC 4122 id of the trip's owner, or null when the trip is anonymous
     * (no account attached) or does not exist. Used to target server pushes (#1124).
     */
    public function getOwnerId(string $tripId): ?string;
}
