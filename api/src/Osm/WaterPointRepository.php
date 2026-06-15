<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads real drinking-water points from the local-first Tier-1 index along the
 * route corridor (ST_DWithin), replacing the runtime Overpass "cemetery proxy"
 * (ADR-040).
 *
 * Every row in osm.water_points is a potable source by construction: the
 * provisioner flex style (provisioner/osm2pgsql/tier1.lua) only imports
 * drinking_water, water_point, water_tap and potable fountains/springs. The
 * query therefore returns all categories rather than filtering to a single one,
 * which would drop valid taps and springs.
 */
final readonly class WaterPointRepository implements WaterPointRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Drinking-water points whose geometry is within $radiusMeters of the route corridor.
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
                FROM osm.water_points
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

        $waterPoints = [];
        foreach ($rows as $row) {
            $waterPoints[] = [
                'name' => null !== $row['name'] ? (string) $row['name'] : null,
                'category' => (string) $row['category'],
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
            ];
        }

        return $waterPoints;
    }
}
