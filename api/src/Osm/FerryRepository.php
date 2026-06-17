<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads ferry crossings from the local-first Tier-1 index (ADR-040): the
 * osm.ferries lines (route=ferry ways + relations) running within a tolerance of
 * a stage's route. A route that takes a ferry has its geometry follow the ferry
 * line, so proximity to the stage line is the signal. Drives the ferry-crossing
 * alert.
 */
final readonly class FerryRepository implements FerryRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function findNearStage(array $stagePoints, int $toleranceMeters): array
    {
        if (\count($stagePoints) < 2) {
            return [];
        }

        $line = WktGeometry::lineStringOrPoint($stagePoints);

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT f.name,
                       ST_Y(ST_ClosestPoint(f.geom, s.line)) AS lat,
                       ST_X(ST_ClosestPoint(f.geom, s.line)) AS lon
                FROM osm.ferries f,
                     (SELECT ST_SetSRID(ST_GeomFromText(:wkt), 4326) AS line) AS s
                WHERE ST_DWithin(f.geom::geography, s.line::geography, :tol)
                SQL,
            [
                'wkt' => $line,
                'tol' => $toleranceMeters,
            ],
        );

        $ferries = [];
        foreach ($rows as $row) {
            $ferries[] = [
                'name' => null !== $row['name'] ? (string) $row['name'] : null,
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
            ];
        }

        return $ferries;
    }
}
