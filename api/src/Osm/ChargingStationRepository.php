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
     * Charging stations whose geometry is within $radiusMeters of the route corridor.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array
    {
        if ([] === $route) {
            return [];
        }

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT name, category, ST_Y(geom) AS lat, ST_X(geom) AS lon
                FROM osm.charging_stations
                WHERE ST_DWithin(
                    geom::geography,
                    ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography,
                    :radius
                )
                SQL,
            [
                'wkt' => WktGeometry::lineStringOrPoint($route),
                'radius' => $radiusMeters,
            ],
        );

        $stations = [];
        foreach ($rows as $row) {
            $stations[] = [
                'name' => null !== $row['name'] ? (string) $row['name'] : null,
                'category' => (string) $row['category'],
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
            ];
        }

        return $stations;
    }
}
