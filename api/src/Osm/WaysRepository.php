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
 *
 * This is the only Tier-1 corridor scan that runs against linestrings of the
 * whole road network (the POI tables hold sparse points), so the per-row
 * `geom::geography` cast that defeats the GiST index is unacceptable here. The
 * query keeps the exact ST_DWithin(geography, 100 m) predicate but gates it
 * behind an index-usable `geom && <expanded bbox>` pre-filter (ADR-043, PR1):
 * the bounding box strictly contains the 100 m corridor, so the candidate set
 * is a superset and the returned ways are byte-for-byte identical to the
 * unoptimised scan. See WaysIndexReadTest for the behaviour guard.
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
                WITH corridor AS (
                    SELECT ST_SetSRID(ST_GeomFromText(:wkt), 4326) AS geom
                ),
                bbox AS (
                    -- Pad the corridor envelope by the search radius converted to
                    -- degrees. Latitude: a constant ~111 320 m/deg. Longitude: the
                    -- metres-per-degree shrink with latitude, so divide by the
                    -- cosine at the envelope's highest |lat| (the widest box, the
                    -- safe over-cover), clamped to keep the box finite near the
                    -- poles. The result strictly contains the metric ST_DWithin
                    -- corridor, so the candidate set is a superset.
                    SELECT ST_Expand(
                        ST_Envelope(geom),
                        :radius / (111320.0 * GREATEST(
                            cos(radians(LEAST(
                                GREATEST(
                                    abs(ST_YMin(ST_Envelope(geom))),
                                    abs(ST_YMax(ST_Envelope(geom)))
                                ) + :radius / 111320.0,
                                89.9
                            ))),
                            0.01
                        )),
                        :radius / 111320.0
                    ) AS geom
                    FROM corridor
                )
                SELECT ST_Y(_c.centroid) AS lat,
                       ST_X(_c.centroid) AS lon,
                       ST_Length(w.geom::geography) AS length,
                       w.tags->>'surface' AS surface,
                       w.tags->>'highway' AS highway,
                       w.tags->>'cycleway' AS cycleway,
                       w.tags->>'cycleway:right' AS cycleway_right,
                       w.tags->>'cycleway:left' AS cycleway_left,
                       w.tags->>'cycleway:both' AS cycleway_both,
                       w.tags->>'bicycle' AS bicycle,
                       w.tags->>'maxspeed' AS maxspeed
                FROM osm.ways AS w,
                     corridor AS c,
                     bbox AS b,
                     LATERAL (SELECT ST_Centroid(w.geom) AS centroid) AS _c
                WHERE w.geom && b.geom
                  AND ST_DWithin(
                      w.geom::geography,
                      c.geom::geography,
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
