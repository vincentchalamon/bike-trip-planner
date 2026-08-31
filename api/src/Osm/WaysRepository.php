<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads highway ways from the local-first Tier-1 index along the route corridor,
 * replacing the runtime Overpass ways scan (ADR-040). Each way is reduced in SQL
 * to the shape the terrain analyzers consume: centroid + length (meters, via
 * geography) + the surface/traffic tags. The full linestring stays in the table;
 * only the derived fields cross the wire.
 *
 * Both the length and the centroid describe the *clipped* way -- the portion
 * running inside the metric corridor -- not the whole way. A 40 km departemental
 * that shares 200 m with the route contributes 200 m to the surface / traffic
 * totals, and its centroid lands on that shared portion so the stage
 * distribution (GeometryBasedDistributor) attributes it to the right day.
 *
 * This is the only Tier-1 corridor scan that runs against linestrings of the
 * whole road network (the POI tables hold sparse points), so the per-row
 * `geom::geography` cast that defeats the GiST index is unacceptable here. The
 * metric predicate is an intersection with the buffered corridor, gated behind
 * an index-usable `geom && <expanded bbox>` pre-filter (ADR-043, PR1): the
 * bounding box strictly contains the corridor, so the candidate set is a
 * superset and the result is identical to the unfiltered scan. See
 * WaysIndexReadTest for the behaviour guard.
 *
 * Besides the derived fields, each row carries the ordered geometry of the
 * *clipped* portion (`ST_AsGeoJSON`) so the terrain analyzers can highlight the
 * exact stretch of road an alert refers to on the internal map (issue #982). The
 * geometry is a list of polylines (a clip that enters and leaves the corridor
 * yields a MultiLineString), each a list of `[lat, lon]` pairs.
 *
 * @phpstan-type WayRow = array{lat: float, lon: float, surface: string, tracktype: string, smoothness: string, highway: string, cycleway: string, 'cycleway:right': string, 'cycleway:left': string, 'cycleway:both': string, bicycle: string, maxspeed: string, length: float, geometry: list<list<array{0: float, 1: float}>>}
 */
final readonly class WaysRepository implements WaysRepositoryInterface
{
    public function __construct(private Connection $referenceConnection)
    {
    }

    /**
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<WayRow>
     */
    public function findInCorridor(array $route, int $radiusMeters): array
    {
        if ([] === $route) {
            return [];
        }

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->referenceConnection->fetchAllAssociative(
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
                    -- poles. The result strictly contains the metric corridor
                    -- below, so the candidate set is a superset.
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
                ),
                ridden AS MATERIALIZED (
                    -- The metric corridor itself: the route line buffered by the
                    -- radius in metres (geography buffer, i.e. projected by PostGIS
                    -- in the best local SRID, so the width is metric everywhere).
                    -- MATERIALIZED is load-bearing: inlined, the buffer of a ~1.5k
                    -- point route would be rebuilt for every candidate row.
                    SELECT ST_Buffer(geom::geography, :radius)::geometry AS geom
                    FROM corridor
                ),
                followed AS MATERIALIZED (
                    -- Clip each candidate way to the corridor. Materialised so the
                    -- clip runs once per way and feeds the length, the centroid and
                    -- the highlight geometry below.
                    SELECT w.tags AS tags,
                           ST_Intersection(w.geom, r.geom) AS geom
                    FROM osm.ways AS w,
                         bbox AS b,
                         ridden AS r
                    WHERE w.geom && b.geom
                      AND ST_Intersects(w.geom, r.geom)
                )
                SELECT ST_Y(_c.centroid) AS lat,
                       ST_X(_c.centroid) AS lon,
                       _l.length AS length,
                       ST_AsGeoJSON(f.geom) AS geometry,
                       f.tags->>'surface' AS surface,
                       f.tags->>'tracktype' AS tracktype,
                       f.tags->>'smoothness' AS smoothness,
                       f.tags->>'highway' AS highway,
                       f.tags->>'cycleway' AS cycleway,
                       f.tags->>'cycleway:right' AS cycleway_right,
                       f.tags->>'cycleway:left' AS cycleway_left,
                       f.tags->>'cycleway:both' AS cycleway_both,
                       f.tags->>'bicycle' AS bicycle,
                       f.tags->>'maxspeed' AS maxspeed
                FROM followed AS f,
                     LATERAL (SELECT ST_Length(f.geom::geography) AS length) AS _l,
                     LATERAL (SELECT ST_Centroid(f.geom) AS centroid) AS _c
                -- Ways that only graze the corridor boundary clip to an empty or
                -- punctual geometry: they are not followed, so they are dropped.
                WHERE _l.length > 0
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
                'tracktype' => (string) ($row['tracktype'] ?? ''),
                'smoothness' => (string) ($row['smoothness'] ?? ''),
                'highway' => (string) ($row['highway'] ?? ''),
                'cycleway' => (string) ($row['cycleway'] ?? ''),
                'cycleway:right' => (string) ($row['cycleway_right'] ?? ''),
                'cycleway:left' => (string) ($row['cycleway_left'] ?? ''),
                'cycleway:both' => (string) ($row['cycleway_both'] ?? ''),
                'bicycle' => (string) ($row['bicycle'] ?? ''),
                'maxspeed' => (string) ($row['maxspeed'] ?? ''),
                'length' => (float) $row['length'],
                'geometry' => self::parseGeometry(isset($row['geometry']) ? (string) $row['geometry'] : ''),
            ];
        }

        return $ways;
    }

    /**
     * Turns a GeoJSON LineString / MultiLineString (the clipped corridor portion)
     * into a list of polylines of `[lat, lon]` pairs — the shape the frontend map
     * highlight consumes. GeoJSON stores coordinates as `[lon, lat]`, so each pair
     * is flipped. Anything else (empty, punctual) yields no polyline.
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    public static function parseGeometry(string $geoJson): array
    {
        if ('' === $geoJson) {
            return [];
        }

        /** @var array{type?: string, coordinates?: mixed} $decoded */
        $decoded = json_decode($geoJson, true) ?: [];
        $type = $decoded['type'] ?? '';
        $coordinates = $decoded['coordinates'] ?? [];
        if (!\is_array($coordinates)) {
            return [];
        }

        // Normalise to a list of linestrings: a bare LineString is a single one.
        $lineStrings = 'LineString' === $type ? [$coordinates] : $coordinates;

        $polylines = [];
        foreach ($lineStrings as $lineString) {
            if (!\is_array($lineString)) {
                continue;
            }

            $points = [];
            foreach ($lineString as $point) {
                if (\is_array($point) && isset($point[0], $point[1])) {
                    $points[] = [(float) $point[1], (float) $point[0]];
                }
            }

            if ([] !== $points) {
                $polylines[] = $points;
            }
        }

        return $polylines;
    }
}
