<?php

declare(strict_types=1);

namespace App\Tourism;

use App\Osm\WktGeometry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads DataTourisme accommodations from the local-first `tourism` schema within
 * a radius of the stage end points (ST_DWithin), replacing the runtime
 * DataTourisme REST API (ADR-040).
 */
final readonly class AccommodationRepository implements AccommodationRepositoryInterface
{
    /**
     * Rows kept per stage end point. ScanAccommodationsHandler retains
     * MAX_CANDIDATES_PER_STAGE = 3 per stage after cross-source deduplication and
     * the price ranking, so 30 leaves a 10x margin. It replaces the flat
     * `LIMIT 200`, which both starved long trips and truncated non
     * deterministically for lack of an ORDER BY.
     */
    private const int MAX_ROWS_PER_POINT = 30;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * DataTourisme accommodations of the given categories within $radiusMeters of
     * any point, grouped by the end point they belong to (nearest first within each
     * group) and capped per end point.
     *
     * @param list<array{lat: float, lon: float}> $points
     * @param list<string>                        $categories
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float, capacity: ?int, price: ?float, description: ?string}>
     */
    public function findNear(array $points, int $radiusMeters, array $categories): array
    {
        if ([] === $points || [] === $categories) {
            return [];
        }

        // The cap is per end point, not global: the flat `LIMIT 200` (and any single
        // top-N over the combined multipoint) lets one dense urban stage consume the
        // whole budget and evict a rural stage down to zero candidate. Each row is
        // therefore assigned to its nearest end point (`nearest`, the same rule
        // GeometryBasedDistributor::distributeByEndpoint applies downstream) and
        // ranked inside that partition. ROW_NUMBER over one pass, rather than a
        // LATERAL sub-select per point: the radius filter costs a full scan (no index
        // on `geom::geography`), so a per-point sub-select would repeat it per stage.
        //
        // The order is fully specified — end point, then distance, then the `id`
        // primary key (the DataTourisme URI) — so two runs of the same scan return
        // the same rows in the same order, the reproducibility ADR-040 promises.
        // `ranked.*` carries the ranking helper columns (point_index, distance,
        // point_rank, id); the mapping below reads named keys and ignores them.
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT ranked.*
                FROM (
                    SELECT a.id,
                           a.name, a.category, a.capacity, a.price, a.description,
                           ST_Y(a.geom) AS lat, ST_X(a.geom) AS lon,
                           nearest.point_index, nearest.distance,
                           ROW_NUMBER() OVER (
                               PARTITION BY nearest.point_index
                               ORDER BY nearest.distance, a.id
                           ) AS point_rank
                    FROM tourism.accommodations a
                    CROSS JOIN LATERAL (
                        SELECT pt.path[1] AS point_index, a.geom <-> pt.geom AS distance
                        FROM ST_Dump(ST_SetSRID(ST_GeomFromText(:wkt), 4326)) AS pt
                        ORDER BY a.geom <-> pt.geom, pt.path
                        LIMIT 1
                    ) AS nearest
                    WHERE a.category IN (:categories)
                      AND ST_DWithin(
                          a.geom::geography,
                          ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography,
                          :radius
                      )
                ) AS ranked
                WHERE ranked.point_rank <= :limit
                ORDER BY ranked.point_index, ranked.distance, ranked.id
                SQL,
            [
                'wkt' => WktGeometry::multiPoint($points),
                'radius' => $radiusMeters,
                'categories' => $categories,
                'limit' => self::MAX_ROWS_PER_POINT,
            ],
            [
                'categories' => ArrayParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ],
        );

        $accommodations = [];
        foreach ($rows as $row) {
            $accommodations[] = [
                'name' => null !== $row['name'] ? (string) $row['name'] : null,
                'category' => (string) $row['category'],
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'capacity' => null !== $row['capacity'] ? (int) $row['capacity'] : null,
                'price' => null !== $row['price'] ? (float) $row['price'] : null,
                'description' => null !== $row['description'] && '' !== $row['description'] ? (string) $row['description'] : null,
            ];
        }

        return $accommodations;
    }
}
