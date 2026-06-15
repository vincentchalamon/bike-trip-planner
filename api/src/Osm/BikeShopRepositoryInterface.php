<?php

declare(strict_types=1);

namespace App\Osm;

interface BikeShopRepositoryInterface
{
    /**
     * Bike shops whose geometry is within $radiusMeters of the route corridor.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: ?string, lat: float, lon: float, hasRepair: bool}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array;
}
