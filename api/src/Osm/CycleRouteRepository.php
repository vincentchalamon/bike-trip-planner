<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Measures how much of each stage follows a signed cycle route, from the
 * local-first osm.cycle_routes index (ADR-040): the length of the stage line
 * lying within a tolerance of a cycle route, over the stage length. Drives the
 * per-stage "on cycle network" indicator.
 *
 * All stages are measured in a single query (a stage WKT per row via
 * jsonb_array_elements_text WITH ORDINALITY), avoiding a round trip per stage.
 */
final readonly class CycleRouteRepository implements CycleRouteRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function onNetworkFractions(array $stageGeometries, int $toleranceMeters): array
    {
        if ([] === $stageGeometries) {
            return [];
        }

        // One WKT per stage, index-aligned. A stage with fewer than two points
        // cannot be measured → an empty linestring (length 0) yields fraction 0.
        $wkts = array_map(
            static fn (array $points): string => \count($points) >= 2 ? WktGeometry::lineStringOrPoint($points) : 'LINESTRING EMPTY',
            $stageGeometries,
        );

        // For each stage line, buffer (geography, metres) the cycle routes running
        // within the tolerance, then measure the stage length inside that buffer
        // over the total stage length.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT t.idx AS idx, CASE
                    WHEN ST_Length(stage.g::geography) = 0 THEN 0
                    ELSE COALESCE(ST_Length(ST_Intersection(stage.g, buffer.g)::geography), 0)
                         / ST_Length(stage.g::geography)
                END AS fraction
                FROM jsonb_array_elements_text(:wkts::jsonb) WITH ORDINALITY AS t(wkt, idx)
                CROSS JOIN LATERAL (SELECT ST_SetSRID(ST_GeomFromText(t.wkt), 4326) AS g) AS stage
                LEFT JOIN LATERAL (
                    SELECT ST_Buffer(ST_Collect(c.geom)::geography, :tol)::geometry AS g
                    FROM osm.cycle_routes c
                    -- Index-usable bbox pre-filter (ADR-043 PR1, same shape as
                    -- WaysRepository): the per-row c.geom::geography cast in
                    -- ST_DWithin defeats the GiST index, so gate it behind
                    -- `c.geom && <expanded bbox>`. The box pads the stage envelope
                    -- by the tolerance (lat: ~111 320 m/deg; lon: divided by cos at
                    -- the envelope's highest |lat|, clamped), strictly containing
                    -- the metric corridor — so the candidate set is a superset and
                    -- the measured fraction is identical to the unfiltered scan.
                    WHERE c.geom && ST_Expand(
                            ST_Envelope(stage.g),
                            :tol / (111320.0 * GREATEST(
                                cos(radians(LEAST(
                                    GREATEST(
                                        abs(ST_YMin(ST_Envelope(stage.g))),
                                        abs(ST_YMax(ST_Envelope(stage.g)))
                                    ) + :tol / 111320.0,
                                    89.9
                                ))),
                                0.01
                            )),
                            :tol / 111320.0
                        )
                      AND ST_DWithin(c.geom::geography, stage.g::geography, :tol)
                ) AS buffer ON TRUE
                ORDER BY t.idx
                SQL,
            [
                'wkts' => json_encode($wkts, \JSON_THROW_ON_ERROR),
                'tol' => $toleranceMeters,
            ],
        );

        $fractions = [];
        foreach ($rows as $row) {
            $fractions[] = is_numeric($row['fraction']) ? (float) $row['fraction'] : 0.0;
        }

        return $fractions;
    }
}
