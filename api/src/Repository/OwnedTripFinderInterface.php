<?php

declare(strict_types=1);

namespace App\Repository;

use App\ApiResource\TripRequest;
use App\Entity\User;
use Symfony\Component\Uid\Uuid;

/**
 * Trips read through their owner: ownership checks, the owner's list, the portability export.
 */
interface OwnedTripFinderInterface
{
    /**
     * Owned (non-anonymous) trips whose date range covers the given day, for the
     * weather-safety batch (#1124).
     *
     * @return list<TripRequest>
     */
    public function findOwnedTripsCoveringDate(\DateTimeImmutable $date): array;

    public function isOwnedBy(string $tripId, User $owner): bool;

    /**
     * One page of the owner's trips, newest first.
     *
     * $title is a partial, case-insensitive match; $startsFrom and $endsBy bound the trip's
     * own dates, inclusive.
     *
     * @return list<TripRequest>
     */
    public function findPageOwnedBy(User $owner, ?string $title, ?\DateTimeImmutable $startsFrom, ?\DateTimeImmutable $endsBy, int $offset, int $limit): array;

    /**
     * How many trips {@see self::findPageOwnedBy()} would page through with the same filters.
     */
    public function countOwnedBy(User $owner, ?string $title, ?\DateTimeImmutable $startsFrom, ?\DateTimeImmutable $endsBy): int;

    /**
     * Every trip the owner has, oldest first.
     *
     * @return list<TripRequest>
     */
    public function findAllOwnedBy(User $owner): array;

    /**
     * Ridden distance and stage count per trip, rest days excluded, keyed by RFC 4122 id.
     * A trip without a ridden stage is absent from the map.
     *
     * @param list<Uuid> $tripIds
     *
     * @return array<string, array{float, int}>
     */
    public function stageTotalsByTrip(array $tripIds): array;

    /**
     * The four stage columns the portability export ships, per trip and in stage order,
     * keyed by RFC 4122 id.
     *
     * @param list<Uuid> $tripIds
     *
     * @return array<string, list<array{dayNumber: int, label: string|null, distance: float, elevation: float}>>
     */
    public function stageSummariesByTrip(array $tripIds): array;
}
