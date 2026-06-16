<?php

declare(strict_types=1);

namespace App\Tourism;

use App\Osm\WktGeometry;
use Doctrine\DBAL\Connection;

/**
 * Reads DataTourisme cultural POIs from the local-first `tourism` schema along
 * the route corridor (ST_DWithin), replacing the runtime DataTourisme REST API
 * (ADR-040). The flux is imported by the provisioner; this is a read-only view.
 */
final readonly class CulturalPoiRepository implements CulturalPoiRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float, openingHours: ?string, description: ?string, wikidata: ?string}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array
    {
        if ([] === $route) {
            return [];
        }

        $wkt = WktGeometry::lineStringOrPoint($route);

        // KNN cap (nearest 100) mirrors the OSM cultural read so the merged
        // result stays bounded after the registry combines both sources.
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT name, category, opening_hours, description, wikidata,
                       ST_Y(geom) AS lat, ST_X(geom) AS lon
                FROM tourism.cultural_pois
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
