<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Reads points of interest (food, shops, services, sights) from the local-first
 * Tier-1 index along the route corridor (ST_DWithin), replacing runtime Overpass
 * POI scans (ADR-040).
 */
final readonly class PoiRepository implements PoiRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * POIs whose geometry is within $radiusMeters of the route corridor.
     *
     * @param list<array{lat: float, lon: float}> $route
     *
     * @return list<array{osmType: ?string, osmId: ?int, name: ?string, category: string, lat: float, lon: float, openingHours: ?string, website: ?string}>
     */
    public function findInCorridor(array $route, int $radiusMeters): array
    {
        if ([] === $route) {
            return [];
        }

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT osm_type, osm_id, name, category, opening_hours, website, ST_Y(geom) AS lat, ST_X(geom) AS lon
                FROM osm.pois
                WHERE ST_DWithin(
                    geom::geography,
                    ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography,
                    :radius
                )
                SQL,
            [
                'wkt' => WktGeometry::lineStringOrPoint($route),
                'radius' => $radiusMeters,
            ],
        );

        $pois = [];
        foreach ($rows as $row) {
            $pois[] = [
                // Primary key of the index (ADR-040), carried so a reader can reach
                // the object on openstreetmap.org instead of losing its identity here.
                'osmType' => OsmObjectType::fromChar($row['osm_type']),
                'osmId' => null !== $row['osm_id'] ? (int) $row['osm_id'] : null,
                'name' => null !== $row['name'] ? (string) $row['name'] : null,
                'category' => (string) $row['category'],
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'openingHours' => null !== $row['opening_hours'] && '' !== $row['opening_hours'] ? (string) $row['opening_hours'] : null,
                'website' => null !== $row['website'] && '' !== $row['website'] ? (string) $row['website'] : null,
            ];
        }

        return $pois;
    }
}
