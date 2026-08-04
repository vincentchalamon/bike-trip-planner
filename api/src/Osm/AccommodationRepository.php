<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads accommodations from the local-first Tier-1 index within a radius of the
 * stage end points (ST_DWithin), replacing the runtime Overpass accommodation
 * source (ADR-040). DataTourisme/Wikidata enrichment stays in their own sources.
 */
final readonly class AccommodationRepository implements AccommodationRepositoryInterface
{
    /**
     * Rows kept per stage end point. ScanAccommodationsHandler retains
     * MAX_CANDIDATES_PER_STAGE = 5 per stage after cross-source deduplication and
     * the completeness ranking, so 30 leaves a 6x margin while bounding the scan: a
     * 15 km radius over a dense area otherwise rapatriates every row — and its
     * full `tags` jsonb — for five kept per stage.
     */
    private const int MAX_ROWS_PER_POINT = 30;

    /**
     * Categories present in `osm.accommodations` that are not lodging.
     *
     * `amenity=shelter` is still imported, but only to serve the in-ride "where
     * can I take cover" intent ({@see \App\InRide\InRidePoiRepository}): the
     * measurement in docs/audit/878-hebergements-osm-sans-nom.md found 76% of it
     * to be street furniture, bus shelters above all. Excluding it here — rather
     * than relying on it having left TripRequest::ALL_ACCOMMODATION_TYPES — keeps
     * the guarantee independent of what a caller passes in `$categories`,
     * including a trip persisted before #927 whose list still names it.
     *
     * @var list<string>
     */
    private const array NON_LODGING_CATEGORIES = ['shelter'];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Accommodations of the given categories within $radiusMeters of any point,
     * grouped by the end point they belong to (nearest first within each group)
     * and capped per end point.
     *
     * @param list<array{lat: float, lon: float}> $points
     * @param list<string>                        $categories
     *
     * @return list<array{osmType: ?string, osmId: ?int, name: ?string, category: string, lat: float, lon: float, stars: ?int, capacity: ?int, fee: ?string, website: ?string, wikidata: ?string, openingHours: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, tags: array<string, string>}>
     */
    public function findNear(array $points, int $radiusMeters, array $categories): array
    {
        if ([] === $points || [] === $categories) {
            return [];
        }

        // description / image_url / wikipedia_url are enriched from Wikidata at
        // provision time (ADR-041); website / opening_hours come from OSM tags.
        //
        // The cap is per end point, not global: a single `ORDER BY geom <-> multipoint
        // LIMIT n` is a top-N over the *combined* multipoint, so one dense urban stage
        // can consume the whole budget and evict a rural stage down to zero candidate.
        // Each row is therefore assigned to its nearest end point (`nearest`) and
        // ranked inside that partition, so every stage gets its own MAX_ROWS_PER_POINT.
        // ROW_NUMBER over one pass, rather than a LATERAL sub-select per point: the
        // radius filter costs a full scan (no index on `geom::geography`), so a
        // per-point sub-select would repeat it once per stage.
        //
        // The assignment casts to `geography` on purpose: `<->` on `geometry` is a
        // planar distance in raw WGS84 degrees, where a degree of longitude is
        // cos(latitude) shorter than a degree of latitude, so near the bisector of two
        // end points it can pick a different one than the metric
        // GeometryBasedDistributor::distributeByEndpoint uses downstream — leaving the
        // row ranked under a stage that will never receive it. `geography <->` is
        // metres on the sphere, i.e. the same great circle as HaversineDistance
        // (radii 6371008.8 m vs 6371000 m, a 1.4 ppm scale factor that cannot reorder
        // two rows a human could tell apart).
        //
        // The order is fully specified — end point, then distance, then the
        // (osm_type, osm_id) primary key — so two runs of the same scan return the
        // same rows in the same order. `ranked.*` carries the ranking helper columns
        // (point_index, distance, point_rank, primary key); the mapping below reads
        // named keys and ignores them.
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT ranked.*
                FROM (
                    SELECT a.osm_type, a.osm_id,
                           a.name, a.category, a.stars, a.capacity, a.fee, a.website, a.wikidata,
                           a.opening_hours, a.description, a.image_url, a.wikipedia_url,
                           ST_Y(a.geom) AS lat, ST_X(a.geom) AS lon, a.tags,
                           nearest.point_index, nearest.distance,
                           ROW_NUMBER() OVER (
                               PARTITION BY nearest.point_index
                               ORDER BY nearest.distance, a.osm_type, a.osm_id
                           ) AS point_rank
                    FROM osm.accommodations a
                    CROSS JOIN LATERAL (
                        SELECT pt.path[1] AS point_index,
                               a.geom::geography <-> pt.geom::geography AS distance
                        FROM ST_Dump(ST_SetSRID(ST_GeomFromText(:wkt), 4326)) AS pt
                        ORDER BY a.geom::geography <-> pt.geom::geography, pt.path
                        LIMIT 1
                    ) AS nearest
                    WHERE a.category IN (:categories)
                      AND a.category NOT IN (:nonLodgingCategories)
                      AND ST_DWithin(
                          a.geom::geography,
                          ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography,
                          :radius
                      )
                ) AS ranked
                WHERE ranked.point_rank <= :limit
                ORDER BY ranked.point_index, ranked.distance, ranked.osm_type, ranked.osm_id
                SQL,
            [
                'wkt' => WktGeometry::multiPoint($points),
                'radius' => $radiusMeters,
                'categories' => $categories,
                'nonLodgingCategories' => self::NON_LODGING_CATEGORIES,
                'limit' => self::MAX_ROWS_PER_POINT,
            ],
            [
                'categories' => ArrayParameterType::STRING,
                'nonLodgingCategories' => ArrayParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ],
        );

        $accommodations = [];
        foreach ($rows as $row) {
            $accommodations[] = [
                // Primary key of the index, kept so the rider can reach the object
                // on openstreetmap.org: it used to be dropped at this very first hop.
                'osmType' => OsmObjectType::fromChar($row['osm_type']),
                'osmId' => null !== $row['osm_id'] ? (int) $row['osm_id'] : null,
                'name' => null !== $row['name'] ? (string) $row['name'] : null,
                'category' => (string) $row['category'],
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'stars' => null !== $row['stars'] ? (int) $row['stars'] : null,
                'capacity' => null !== $row['capacity'] ? (int) $row['capacity'] : null,
                'fee' => null !== $row['fee'] ? (string) $row['fee'] : null,
                'website' => null !== $row['website'] ? (string) $row['website'] : null,
                'wikidata' => null !== $row['wikidata'] ? (string) $row['wikidata'] : null,
                'openingHours' => null !== $row['opening_hours'] ? (string) $row['opening_hours'] : null,
                'description' => null !== $row['description'] && '' !== $row['description'] ? (string) $row['description'] : null,
                'imageUrl' => null !== $row['image_url'] && '' !== $row['image_url'] ? (string) $row['image_url'] : null,
                'wikipediaUrl' => null !== $row['wikipedia_url'] && '' !== $row['wikipedia_url'] ? (string) $row['wikipedia_url'] : null,
                'tags' => $this->decodeTags($row['tags']),
            ];
        }

        return $accommodations;
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
