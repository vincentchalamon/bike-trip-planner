<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads mainline railway stations from the local-first Tier-1 index along the
 * route corridor (ST_DWithin), replacing the runtime Overpass railway-station
 * scan (ADR-040).
 */
final readonly class RailwayStationRepository implements RailwayStationRepositoryInterface
{
    public function __construct(private Connection $referenceConnection)
    {
    }

    /**
     * Railway stations whose geometry is within $radiusMeters of the route corridor.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array
    {
        return new CorridorPointQuery($this->referenceConnection, CorridorPointTable::RAILWAY_STATIONS)->find($route, $radiusMeters);
    }
}
