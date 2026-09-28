<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads the points of one Tier-1 point table whose geometry lies within a radius of the
 * route corridor (ST_DWithin, ADR-040). The railway-station, health-service and water-point
 * indexes share this shape exactly; only the table differs.
 */
final readonly class CorridorPointQuery
{
    public function __construct(
        private Connection $connection,
        private CorridorPointTable $table,
    ) {
    }

    /**
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float}>
     */
    public function find(array $route, int $radiusMeters): array
    {
        if ([] === $route) {
            return [];
        }

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                <<<'SQL'
                    SELECT name, category, ST_Y(geom) AS lat, ST_X(geom) AS lon
                    FROM %s
                    WHERE ST_DWithin(
                        geom::geography,
                        ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography,
                        :radius
                    )
                    SQL,
                $this->table->value,
            ),
            [
                'wkt' => WktGeometry::lineStringOrPoint($route),
                'radius' => $radiusMeters,
            ],
        );

        $points = [];
        foreach ($rows as $row) {
            $points[] = [
                'name' => null !== $row['name'] ? (string) $row['name'] : null,
                'category' => (string) $row['category'],
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
            ];
        }

        return $points;
    }
}
