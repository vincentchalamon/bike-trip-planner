<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads bike shops from the local-first Tier-1 index along the route corridor
 * (ST_DWithin), replacing the runtime Overpass bike-shop scan (ADR-040). The
 * repair flag is derived from the raw tags so the analyzer can tell repair shops
 * apart from sale-only outlets.
 */
final readonly class BikeShopRepository implements BikeShopRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Bike shops whose geometry is within $radiusMeters of the route corridor.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: ?string, lat: float, lon: float, hasRepair: bool}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array
    {
        if ([] === $route) {
            return [];
        }

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT name, ST_Y(geom) AS lat, ST_X(geom) AS lon,
                       (COALESCE(tags->>'service:bicycle:repair', '') = 'yes')::int AS has_repair
                FROM osm.bike_shops
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

        $bikeShops = [];
        foreach ($rows as $row) {
            $bikeShops[] = [
                'name' => null !== $row['name'] ? (string) $row['name'] : null,
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'hasRepair' => 1 === (int) $row['has_repair'],
            ];
        }

        return $bikeShops;
    }
}
