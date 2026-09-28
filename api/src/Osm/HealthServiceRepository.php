<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads health services (pharmacies, hospitals, clinics) from the local-first
 * Tier-1 index along the route corridor (ST_DWithin), replacing the runtime
 * Overpass health-service scan (ADR-040).
 */
final readonly class HealthServiceRepository implements HealthServiceRepositoryInterface
{
    public function __construct(private Connection $referenceConnection)
    {
    }

    /**
     * Health services whose geometry is within $radiusMeters of the route corridor.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array
    {
        return new CorridorPointQuery($this->referenceConnection, CorridorPointTable::HEALTH_SERVICES)->find($route, $radiusMeters);
    }
}
