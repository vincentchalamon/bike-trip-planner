<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads e-bike charging stations from the local-first Tier-1 index along the
 * route corridor (ST_DWithin), so the e-bike-range alert can point to the
 * nearest charger without a runtime Overpass scan (ADR-040).
 */
final readonly class ChargingStationRepository implements ChargingStationRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * The charging station nearest to the route corridor, within $radiusMeters,
     * or null if none. ORDER BY ST_Distance + LIMIT 1 lets the GIST index find the
     * single closest charger, instead of fetching every station and scanning in PHP.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return array{name: ?string, category: string, lat: float, lon: float}|null
     */
    public function findNearestInCorridor(array $route, int $radiusMeters): ?array
    {
        if ([] === $route) {
            return null;
        }

        $wkt = WktGeometry::lineStringOrPoint($route);

        /** @var array<string, scalar|null>|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT name, category, ST_Y(geom) AS lat, ST_X(geom) AS lon
                FROM osm.charging_stations
                WHERE ST_DWithin(
                    geom::geography,
                    ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography,
                    :radius
                )
                ORDER BY ST_Distance(
                    geom::geography,
                    ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography
                )
                LIMIT 1
                SQL,
            [
                'wkt' => $wkt,
                'radius' => $radiusMeters,
            ],
        );

        if (false === $row) {
            return null;
        }

        return [
            'name' => null !== $row['name'] ? (string) $row['name'] : null,
            'category' => (string) $row['category'],
            'lat' => (float) $row['lat'],
            'lon' => (float) $row['lon'],
        ];
    }
}
