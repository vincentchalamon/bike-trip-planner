<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads highway ways from the local-first Tier-1 index along the route corridor
 * (ST_DWithin), replacing the runtime Overpass ways scan (ADR-040). Each way is
 * reduced in SQL to the shape the terrain analyzers consume: centroid + length
 * (meters, via geography) + the surface/traffic tags. The full linestring stays
 * in the table; only the derived fields cross the wire.
 */
final readonly class WaysRepository implements WaysRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{lat: float, lon: float, surface: string, highway: string, cycleway: string, 'cycleway:right': string, 'cycleway:left': string, 'cycleway:both': string, bicycle: string, maxspeed: string, length: float}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array
    {
        if ([] === $route) {
            return [];
        }

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT ST_Y(_c.centroid) AS lat,
                       ST_X(_c.centroid) AS lon,
                       ST_Length(geom::geography) AS length,
                       tags->>'surface' AS surface,
                       tags->>'highway' AS highway,
                       tags->>'cycleway' AS cycleway,
                       tags->>'cycleway:right' AS cycleway_right,
                       tags->>'cycleway:left' AS cycleway_left,
                       tags->>'cycleway:both' AS cycleway_both,
                       tags->>'bicycle' AS bicycle,
                       tags->>'maxspeed' AS maxspeed
                FROM osm.ways,
                LATERAL (SELECT ST_Centroid(geom) AS centroid) AS _c
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

        $ways = [];
        foreach ($rows as $row) {
            $ways[] = [
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'surface' => (string) ($row['surface'] ?? ''),
                'highway' => (string) ($row['highway'] ?? ''),
                'cycleway' => (string) ($row['cycleway'] ?? ''),
                'cycleway:right' => (string) ($row['cycleway_right'] ?? ''),
                'cycleway:left' => (string) ($row['cycleway_left'] ?? ''),
                'cycleway:both' => (string) ($row['cycleway_both'] ?? ''),
                'bicycle' => (string) ($row['bicycle'] ?? ''),
                'maxspeed' => (string) ($row['maxspeed'] ?? ''),
                'length' => (float) $row['length'],
            ];
        }

        return $ways;
    }
}
