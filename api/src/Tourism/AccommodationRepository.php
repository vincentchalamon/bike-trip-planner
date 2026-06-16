<?php

declare(strict_types=1);

namespace App\Tourism;

use App\Osm\WktGeometry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads DataTourisme accommodations from the local-first `tourism` schema within
 * a radius of the stage end points (ST_DWithin), replacing the runtime
 * DataTourisme REST API (ADR-040).
 */
final readonly class AccommodationRepository implements AccommodationRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
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
                LIMIT 200
                SQL,
            [
                'wkt' => WktGeometry::multiPoint($points),
                'radius' => $radiusMeters,
                'categories' => $categories,
            ],
            [
                'categories' => ArrayParameterType::STRING,
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
