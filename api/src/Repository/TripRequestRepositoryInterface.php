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
     * Returns a single stage's route geometry, in travel order, projected to 2D.
     *
     * Feeds the in-ride detour calculation ({@see \App\InRide\DetourCalculator}),
     * which is planar; `ele` is intentionally dropped. A read of only the geometry,
     * not the whole aggregate ({@see self::getStages()} hydrates weather, POIs,
     * accommodations…).
     *
     * @return list<array{lat: float, lon: float}>|null null when the trip, the day,
     *                                                  or the geometry does not exist
     */
    public function getStageGeometry(string $tripId, int $dayNumber): ?array;

    /**
     * Persists a single stage's weather atomically, keyed by dayNumber.
     *
     * Parallel enrichment handlers each own one JSONB column; routing them through
     * {@see self::storeStages()} re-writes the whole stages collection, so a slow
     * handler reading a stale snapshot overwrites a sibling's freshly-written column
     * (the weather/accommodations "disappear" bug — recette #649).
     */
    public function updateStageWeather(string $tripId, int $dayNumber, ?WeatherForecast $weather): void;

    /**
     * Persists a single stage's alerts atomically (see {@see self::updateStageWeather()}).
     *
     * @param list<Alert> $alerts
     */
    public function updateStageAlerts(string $tripId, int $dayNumber, array $alerts): void;

    /**
     * Persists a single stage's curated resupply atomically (see {@see self::updateStageWeather()}).
     */
    public function updateStageResupply(string $tripId, int $dayNumber, Resupply $resupply): void;

    /**
     * Persists a single stage's accommodations atomically (see {@see self::updateStageWeather()}).
     *
     * @param list<Accommodation> $accommodations
     */
    public function updateStageAccommodations(string $tripId, int $dayNumber, array $accommodations): void;

    /**
     * Persists a single stage's reverse-geocoded endpoint labels atomically (see {@see self::updateStageWeather()}).
     */
    public function updateStageLabels(string $tripId, int $dayNumber, ?string $startLabel, ?string $endLabel): void;

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
