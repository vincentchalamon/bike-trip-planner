<?php

declare(strict_types=1);

namespace App\Repository;

use App\ApiResource\TripRequest;

/**
 * A trip's own fields: its request parameters, title, source type, status, locale and owner.
 *
 * The stages, and the structural version their writes move, belong to {@see TripStageStoreInterface}.
 */
interface TripRequestRepositoryInterface
{
    /**
     * $locale is written in the same flush as the trip. It is not read from $request: the
     * client never chooses it, the caller passes the authenticated account's.
     */
    public function initializeTrip(string $tripId, TripRequest $request, ?string $locale = null): void;

    public function getRequest(string $tripId): ?TripRequest;

    public function storeRequest(string $tripId, TripRequest $request): void;

    public function getTitle(string $tripId): ?string;

    public function storeTitle(string $tripId, ?string $title): void;

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
