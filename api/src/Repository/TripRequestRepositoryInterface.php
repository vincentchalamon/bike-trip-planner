<?php

declare(strict_types=1);

namespace App\Repository;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;

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
     * @param callable(list<Stage>): list<Stage> $mutator
     *
     * @return list<Stage>|null the stages as written, or null when the trip is unknown
     */
    public function mutateStages(string $tripId, callable $mutator): ?array;

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
     * @return int the new version, or 0 when the trip is unknown
     */
    public function bumpVersion(string $tripId): int;

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
     * Persists a single stage's alerts atomically (see {@see self::updateStageWeather()}).
     *
     * @param list<Alert> $alerts
     */
    public function updateStageAlerts(string $tripId, string $stageId, array $alerts): void;

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
