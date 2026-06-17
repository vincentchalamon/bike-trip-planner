<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads fords from the local-first Tier-1 index (ADR-040): the osm.fords points
 * within a tolerance of a stage's route. Drives the ford alert (escalated to a
 * warning when rain is forecast for the stage).
 */
final readonly class FordRepository implements FordRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function findNearStage(array $stagePoints, int $toleranceMeters): array
    {
        if (\count($stagePoints) < 2) {
            return [];
        }

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT name, ST_Y(geom) AS lat, ST_X(geom) AS lon
                FROM osm.fords
                WHERE ST_DWithin(
                    geom::geography,
                    ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography,
                    :tol
                )
                SQL,
            [
                'wkt' => WktGeometry::lineStringOrPoint($stagePoints),
                'tol' => $toleranceMeters,
            ],
        );

        $fords = [];
        foreach ($rows as $row) {
            $fords[] = [
                'name' => null !== $row['name'] ? (string) $row['name'] : null,
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
            ];
        }

        return $fords;
    }
}
