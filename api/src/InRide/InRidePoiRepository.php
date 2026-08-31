<?php

declare(strict_types=1);

namespace App\InRide;

use Doctrine\DBAL\Connection;

/**
 * Reads nearby in-ride POIs from the local-first Tier-1 index (ADR-040), mapping
 * each in-ride intent category to its osm.* table around the rider position —
 * replacing the runtime Overpass in-ride scan and its 5-minute cache.
 *
 * GiST caveat (same as {@see \App\Osm\AccommodationRepository}): the
 * `ST_DWithin(geom::geography, ...)` radius predicate does NOT use the GiST index
 * on `geom` (it casts to `geography`); it is the KNN `ORDER BY geom <-> point
 * LIMIT n` that the index accelerates and that bounds the scan. The two work
 * together: the ORDER BY caps the candidate set to the n nearest, ST_DWithin then
 * drops those past the radius.
 */
final readonly class InRidePoiRepository implements InRidePoiRepositoryInterface
{
    public function __construct(private Connection $referenceConnection)
    {
    }

    public function findNearby(float $lat, float $lon, int $radiusMeters, InRidePoiCategory $category): array
    {
        [$table, $openingHours, $filters] = $this->target($category);

        $where = ['ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography, :radius)'];
        foreach ($filters as $filter) {
            $where[] = $filter;
        }

        // A named venue is required for buckets where an unnamed row is not
        // actionable; pushed to SQL so it does not eat into candidateLimit().
        if ($category->requiresName()) {
            $where[] = "name IS NOT NULL AND btrim(name) <> ''";
        }

        $whereSql = implode("\n              AND ", $where);
        $limit = $category->candidateLimit();

        // $table/$openingHours/$limit come from the enum, never from a caller,
        // so interpolating them is safe; the runtime values stay bound.
        $sql = <<<SQL
            SELECT osm_type, osm_id, name, category, {$openingHours} AS opening_hours,
                   ST_Y(geom) AS lat, ST_X(geom) AS lon, tags
            FROM {$table}
            WHERE {$whereSql}
            ORDER BY geom <-> ST_SetSRID(ST_MakePoint(:lon, :lat), 4326) LIMIT {$limit}
            SQL;

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->referenceConnection->fetchAllAssociative($sql, [
            'lat' => $lat,
            'lon' => $lon,
            'radius' => $radiusMeters,
        ]);

        $features = [];
        foreach ($rows as $row) {
            $features[] = [
                'osmType' => (string) $row['osm_type'],
                'osmId' => (int) $row['osm_id'],
                'name' => null === $row['name'] ? null : (string) $row['name'],
                'category' => (string) $row['category'],
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'openingHours' => null === $row['opening_hours'] ? null : (string) $row['opening_hours'],
                'tags' => $this->decodeTags($row['tags']),
            ];
        }

        return $features;
    }

    /**
     * Table, opening-hours source expression and extra WHERE clauses for a bucket.
     *
     * The shelter bucket keeps the exclusion-list stance decided in #928, NOT the
     * strict whitelist the README carried before it: on the ride an `amenity=shelter`
     * of any kind keeps rain off, so only the useless street furniture is dropped
     * (`carport`, `gazebo`, `umbrella`, `shopping_cart`), and `public_transport`
     * (the bus shelter, 75% of the layer) is deliberately KEPT — the reader labels
     * it distinctly downstream (`shelter_bus` -> "Abribus"). Unlike lodging
     * ({@see \App\Osm\OsmAccommodationSource}), an unnamed shelter is not discarded.
     *
     * Resupply reads the shopping half of osm.pois; its whitelist already leaves out
     * `fuel` and `pharmacy` (a pharmacy is served by the health bucket instead), so a
     * pharmacy indexed in both osm.pois and osm.health_services surfaces once per
     * search, never twice. Charging keeps only bike-usable posts (`bicycle=yes` or a
     * bike-compatible socket key) so an unqualified car charger is dropped.
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private function target(InRidePoiCategory $category): array
    {
        return match ($category) {
            InRidePoiCategory::WATER => ['osm.water_points', "tags->>'opening_hours'", []],
            InRidePoiCategory::SHELTER => ['osm.accommodations', 'opening_hours', [
                "category = 'shelter'",
                "coalesce(tags->>'shelter_type', '') NOT IN ('carport', 'gazebo', 'umbrella', 'shopping_cart')",
            ]],
            InRidePoiCategory::FOOD => ['osm.pois', 'opening_hours', [
                "category IN ('restaurant', 'cafe', 'fast_food', 'bar', 'pub')",
            ]],
            InRidePoiCategory::RESUPPLY => ['osm.pois', 'opening_hours', [
                "category IN ('supermarket', 'convenience', 'bakery', 'butcher', 'greengrocer', 'deli', 'general', 'pastry', 'farm', 'marketplace')",
            ]],
            InRidePoiCategory::MECHANIC => ['osm.bike_shops', "tags->>'opening_hours'", []],
            InRidePoiCategory::HEALTH => ['osm.health_services', "tags->>'opening_hours'", []],
            InRidePoiCategory::TRAIN => ['osm.railway_stations', "tags->>'opening_hours'", []],
            InRidePoiCategory::CHARGING => ['osm.charging_stations', "tags->>'opening_hours'", [
                "(tags->>'bicycle' = 'yes' OR jsonb_exists(tags, 'socket:schuko') OR jsonb_exists(tags, 'socket:typee') OR jsonb_exists(tags, 'socket:type2'))",
            ]],
        };
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
