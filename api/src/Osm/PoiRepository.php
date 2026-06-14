<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads points of interest (food, shops, services, sights) from the local-first
 * Tier-1 index along the route corridor (ST_DWithin), replacing runtime Overpass
 * POI scans (ADR-040).
 */
final readonly class PoiRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * POIs whose geometry is within $radiusMeters of the route corridor.
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
                FROM osm.pois
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

        $pois = [];
        foreach ($rows as $row) {
            $pois[] = [
                'name' => null !== $row['name'] ? (string) $row['name'] : null,
                'category' => (string) $row['category'],
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
            ];
        }

        return $pois;
    }
}
