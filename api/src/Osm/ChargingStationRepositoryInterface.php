<?php

declare(strict_types=1);

namespace App\Osm;

interface ChargingStationRepositoryInterface
{
    /**
     * The charging station nearest to the route corridor, within $radiusMeters,
     * or null if none. Resolved by the DB (GIST index + ST_Distance) since the
     * e-bike-range alert only needs the single closest charger.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return array{name: ?string, category: string, lat: float, lon: float}|null
     */
    public function findNearestInCorridor(array $route, int $radiusMeters): ?array;
}
