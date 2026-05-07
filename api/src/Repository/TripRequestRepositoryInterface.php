<?php

declare(strict_types=1);

namespace App\Repository;

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
     * Persists the LLaMA 8B pass-1 AI analysis for a single stage atomically.
     *
     * Targeted by `dayNumber` (1-indexed, matches {@see Stage::$dayNumber}). Required
     * because parallel `AnalyzeStageWithLlmHandler` workers each update one stage
     * independently — using {@see self::storeStages()} would wipe stages still
     * being processed by a sibling worker.
     *
     * Returns silently if the trip or matching stage does not exist anymore (e.g.
     * the trip was deleted between dispatch and consumption).
     *
     * @param array{narrative: string, insights: list<string>, suggestions: list<string>, model: string, promptVersion: int, generatedAt: string}|null $aiAnalysis
     */
    public function updateStageAiAnalysis(string $tripId, int $dayNumber, ?array $aiAnalysis): void;

    /**
     * Persists the LLaMA 8B pass-2 trip overview atomically (issue #302).
     *
     * Returns silently if the trip does not exist anymore (e.g. the trip was
     * deleted between dispatch and consumption).
     *
     * @param array{narrative: string, patterns: list<string>, recommendations: list<string>, crossStageAlerts: list<string>, model: string, promptVersion: int, generatedAt: string}|null $aiOverview
     */
    public function updateTripAiOverview(string $tripId, ?array $aiOverview): void;

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

    public function storeLocale(string $tripId, string $locale): void;

    public function getLocale(string $tripId): ?string;
}
