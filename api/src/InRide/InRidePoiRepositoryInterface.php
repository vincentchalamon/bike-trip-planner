<?php

declare(strict_types=1);

namespace App\InRide;

interface InRidePoiRepositoryInterface
{
    /**
     * Nearby features of the given in-ride category within $radiusMeters of the
     * rider position, with their raw OSM tags (name, opening_hours, phone...).
     *
     * @return list<array{lat: float, lon: float, tags: array<string, string>}>
     */
    public function findNearby(float $lat, float $lon, int $radiusMeters, string $category): array;
}
