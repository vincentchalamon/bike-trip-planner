<?php

declare(strict_types=1);

namespace App\InRide;

interface InRidePoiRepositoryInterface
{
    /**
     * Nearby features of the given in-ride category within $radiusMeters of the
     * rider position. `name` and `openingHours` are read from a column where the
     * table has one, otherwise from `tags->>'opening_hours'`; `osmType`/`osmId`
     * carry the index primary key so a suggestion can be de-duplicated and linked
     * back to openstreetmap.org.
     *
     * @return list<array{osmType: string, osmId: int, name: ?string, category: string, lat: float, lon: float, openingHours: ?string, tags: array<string, string>}>
     */
    public function findNearby(float $lat, float $lon, int $radiusMeters, InRidePoiCategory $category): array;
}
