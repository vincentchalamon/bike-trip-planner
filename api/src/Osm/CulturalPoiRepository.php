<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads cultural POIs from the local-first Tier-1 index along the route corridor
 * (ST_DWithin), replacing the runtime Overpass cultural-POI scan (ADR-040). The
 * stored category is the resolved type (tourism or historic value).
 */
final readonly class CulturalPoiRepository implements CulturalPoiRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Cultural POIs within $radiusMeters of the route corridor, nearest first.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float, wikidata: ?string}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array
    {
        if ([] === $route) {
            return [];
        }

        $wkt = WktGeometry::lineStringOrPoint($route);

        // `ORDER BY geom <-> route LIMIT 100` (GiST KNN) caps the result like the
        // old Overpass `out center tags 100;`, fetching the nearest cultural POIs.
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT name, category, wikidata, ST_Y(geom) AS lat, ST_X(geom) AS lon
                FROM osm.cultural_pois
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
                'wikidata' => null !== $row['wikidata'] && '' !== $row['wikidata'] ? (string) $row['wikidata'] : null,
            ];
        }

        return $pois;
    }
}
