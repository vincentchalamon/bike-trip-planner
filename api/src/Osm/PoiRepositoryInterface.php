<?php

declare(strict_types=1);

namespace App\Osm;

interface PoiRepositoryInterface
{
    /**
     * POIs whose geometry is within $radiusMeters of the route corridor.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{osmType: ?string, osmId: ?int, name: ?string, category: string, lat: float, lon: float, openingHours: ?string, website: ?string}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array;
}
