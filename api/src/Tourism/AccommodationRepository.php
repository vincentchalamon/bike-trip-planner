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
     * Rows fetched per stage end point. ScanAccommodationsHandler retains
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
     * any point, nearest first.
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

        // `ORDER BY geom <-> multipoint` is the GiST KNN order (like
        // CulturalPoiRepository), so the LIMIT keeps the nearest rows; `id` (the
        // DataTourisme URI, primary key) breaks distance ties so two runs of the
        // same scan return the same rows in the same order — the reproducibility
        // ADR-040 promises.
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT name, category, capacity, price, description,
                       ST_Y(geom) AS lat, ST_X(geom) AS lon
                FROM tourism.accommodations
                WHERE category IN (:categories)
                  AND ST_DWithin(
                      geom::geography,
                      ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography,
                      :radius
                  )
                ORDER BY geom <-> ST_SetSRID(ST_GeomFromText(:wkt), 4326), id
                LIMIT :limit
                SQL,
            [
                'wkt' => WktGeometry::multiPoint($points),
                'radius' => $radiusMeters,
                'categories' => $categories,
                'limit' => \count($points) * self::MAX_ROWS_PER_POINT,
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
