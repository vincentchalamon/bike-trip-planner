<?php

declare(strict_types=1);

namespace App\Tourism;

use App\Osm\WktGeometry;
use Doctrine\DBAL\Connection;

/**
 * Reads DataTourisme food POIs (eateries + food shops) from the local-first
 * `tourism` schema along the route corridor (ST_DWithin), feeding the resupply
 * scan alongside the OSM pois (ADR-040). The flux is imported by the provisioner;
 * this is a read-only view.
 */
final readonly class FoodPoiRepository implements FoodPoiRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function findInCorridor(array $route, int $radiusMeters): array
    {
        if ([] === $route) {
            return [];
        }

        $wkt = WktGeometry::lineStringOrPoint($route);

        // KNN cap (nearest 100) mirrors the OSM/cultural reads so the merged
        // result stays bounded after the registry combines both sources.
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT name, category, opening_hours, description, wikidata,
                       ST_Y(geom) AS lat, ST_X(geom) AS lon
                FROM tourism.food_pois
                WHERE ST_DWithin(
                    geom::geography,
                    ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography,
                    :radius
                )
                ORDER BY geom <-> ST_SetSRID(ST_GeomFromText(:wkt), 4326)
                LIMIT 100
                SQL,
            [
                'wkt' => $wkt,
                'radius' => $radiusMeters,
            ],
        );

        $pois = [];
        foreach ($rows as $row) {
            $pois[] = [
                'name' => null !== $row['name'] ? (string) $row['name'] : null,
                'category' => (string) $row['category'],
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'openingHours' => null !== $row['opening_hours'] && '' !== $row['opening_hours'] ? (string) $row['opening_hours'] : null,
                'description' => null !== $row['description'] && '' !== $row['description'] ? (string) $row['description'] : null,
                'wikidata' => null !== $row['wikidata'] && '' !== $row['wikidata'] ? (string) $row['wikidata'] : null,
            ];
        }

        return $pois;
    }
}
