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
     * @return list<array{name: ?string, category: string, lat: float, lon: float}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array;
}
