<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The route points a trip is computed from, kept only while it is being computed.
 *
 * Nothing here is persisted: the raw and decimated points and the per-track data of a
 * Komoot collection live in the `cache.trip_state` pool (Redis) for half an hour (ADR-022).
 * Split out of {@see TripRequestRepositoryInterface}, which is about the database rows, so a
 * consumer that only reads points does not depend on the trip and its stages, and so the
 * stage lock decorator has no pass-through to write for them.
 */
interface TransientTripPointsStoreInterface
{
    /** @param list<array{lat: float, lon: float, ele: float}> $rawPoints */
    public function storeRawPoints(string $tripId, array $rawPoints): void;

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getRawPoints(string $tripId): ?array;

    /** @param list<array{lat: float, lon: float, ele: float}> $decimatedPoints */
    public function storeDecimatedPoints(string $tripId, array $decimatedPoints): void;

    /** @return list<array{lat: float, lon: float, ele: float}>|null */
    public function getDecimatedPoints(string $tripId): ?array;

    /**
     * Stores multi-track data for Komoot Collection source type.
     *
     * @param list<list<array{lat: float, lon: float, ele: float}>> $tracksData
     */
    public function storeTracksData(string $tripId, array $tracksData): void;

    /** @return list<list<array{lat: float, lon: float, ele: float}>>|null */
    public function getTracksData(string $tripId): ?array;
}
