<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Measures how much of a stage follows a signed cycle route, from the local-first
 * osm.cycle_routes index (ADR-040): the length of the stage line lying within a
 * tolerance of a cycle route, over the stage length. Drives the per-stage
 * "on cycle network" indicator.
 */
final readonly class CycleRouteRepository implements CycleRouteRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function onNetworkFraction(array $stagePoints, int $toleranceMeters): float
    {
        if (\count($stagePoints) < 2) {
            return 0.0;
        }

        // Buffer (geography, metres) the cycle routes running within the tolerance
        // of the stage, then measure the stage length inside that buffer over the
        // total stage length. A stage with no nearby cycle route yields 0.
        $fraction = $this->connection->fetchOne(
            <<<'SQL'
                SELECT CASE
                    WHEN ST_Length(stage.g::geography) = 0 THEN 0
                    ELSE COALESCE(ST_Length(ST_Intersection(stage.g, buffer.g)::geography), 0)
                         / ST_Length(stage.g::geography)
                END
                FROM (SELECT ST_SetSRID(ST_GeomFromText(:wkt), 4326) AS g) AS stage
                LEFT JOIN LATERAL (
                    SELECT ST_Buffer(ST_Collect(c.geom)::geography, :tol)::geometry AS g
                    FROM osm.cycle_routes c
                    WHERE ST_DWithin(c.geom::geography, stage.g::geography, :tol)
                ) AS buffer ON TRUE
                SQL,
            [
                'wkt' => WktGeometry::lineStringOrPoint($stagePoints),
                'tol' => $toleranceMeters,
            ],
        );

        return is_numeric($fraction) ? (float) $fraction : 0.0;
    }
}
