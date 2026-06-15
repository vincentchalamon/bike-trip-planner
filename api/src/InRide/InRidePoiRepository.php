<?php

declare(strict_types=1);

namespace App\InRide;

use Doctrine\DBAL\Connection;

/**
 * Reads nearby in-ride POIs from the local-first Tier-1 index (ADR-040), mapping
 * each in-ride intent category to its osm.* table around the rider position —
 * replacing the runtime Overpass in-ride scan and its 5-minute cache.
 */
final readonly class InRidePoiRepository implements InRidePoiRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function findNearby(float $lat, float $lon, int $radiusMeters, string $category): array
    {
        // `ORDER BY geom <-> point LIMIT 50` uses the GiST KNN operator to fetch
        // the 50 nearest candidates only (restoring the old Overpass cap), so a
        // dense city centre never floods buildSuggestions' per-row work.
        $sql = match ($category) {
            PoiSuggestion::CATEGORY_WATER => <<<'SQL'
                SELECT ST_Y(geom) AS lat, ST_X(geom) AS lon, tags FROM osm.water_points
                WHERE ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography, :radius)
                ORDER BY geom <-> ST_SetSRID(ST_MakePoint(:lon, :lat), 4326) LIMIT 50
                SQL,
            PoiSuggestion::CATEGORY_SHELTER => <<<'SQL'
                SELECT ST_Y(geom) AS lat, ST_X(geom) AS lon, tags FROM osm.accommodations
                WHERE category = 'shelter'
                  AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography, :radius)
                ORDER BY geom <-> ST_SetSRID(ST_MakePoint(:lon, :lat), 4326) LIMIT 50
                SQL,
            PoiSuggestion::CATEGORY_FOOD => <<<'SQL'
                SELECT ST_Y(geom) AS lat, ST_X(geom) AS lon, tags FROM osm.pois
                WHERE category IN ('restaurant', 'cafe', 'fast_food')
                  AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography, :radius)
                ORDER BY geom <-> ST_SetSRID(ST_MakePoint(:lon, :lat), 4326) LIMIT 50
                SQL,
            PoiSuggestion::CATEGORY_MECHANIC => <<<'SQL'
                SELECT ST_Y(geom) AS lat, ST_X(geom) AS lon, tags FROM osm.bike_shops
                WHERE ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography, :radius)
                ORDER BY geom <-> ST_SetSRID(ST_MakePoint(:lon, :lat), 4326) LIMIT 50
                SQL,
            default => null,
        };

        if (null === $sql) {
            return [];
        }

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, [
            'lat' => $lat,
            'lon' => $lon,
            'radius' => $radiusMeters,
        ]);

        $features = [];
        foreach ($rows as $row) {
            $features[] = [
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'tags' => $this->decodeTags($row['tags']),
            ];
        }

        return $features;
    }

    /**
     * @return array<string, string>
     */
    private function decodeTags(mixed $raw): array
    {
        if (!\is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $tags = [];
        foreach ($decoded as $key => $value) {
            if (is_scalar($value)) {
                $tags[(string) $key] = (string) $value;
            }
        }

        return $tags;
    }
}
