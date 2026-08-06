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
     * Rows kept per stage end point. ScanAccommodationsHandler retains
     * MAX_CANDIDATES_PER_STAGE = 5 per stage after cross-source deduplication and
     * the completeness ranking, so 30 leaves a 6x margin. It replaces the flat
     * `LIMIT 200`, which both starved long trips and truncated non
     * deterministically for lack of an ORDER BY.
     */
    private const int MAX_ROWS_PER_POINT = 30;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * DataTourisme accommodations of the given categories within $radiusMeters of
     * any point, grouped by the end point they belong to (nearest first within each
     * group) and capped per end point.
     *
     * @param list<array{lat: float, lon: float}> $points
     * @param list<string>                        $categories
     *
     * `name` is non-nullable by construction since #884: completeness is decided at import
     * time and a per-category CHECK enforces it, and this query excludes the one exempt
     * category (`shelter`). A row without a name therefore cannot exist among these results.
     *
     * @return list<array{name: string, category: string, lat: float, lon: float, capacity: ?int, price: ?float, description: ?string, website: ?string, phone: ?string, openingHours: ?string, wikidata: ?string, imageUrl: ?string, wikipediaUrl: ?string, tags: array<string, string>}>
     */
    public function findNear(array $points, int $radiusMeters, array $categories): array
    {
        if ([] === $points || [] === $categories) {
            return [];
        }

        // The cap is per end point, not global: the flat `LIMIT 200` (and any single
        // top-N over the combined multipoint) lets one dense urban stage consume the
        // whole budget and evict a rural stage down to zero candidate. Each row is
        // therefore assigned to its nearest end point (`nearest`) and ranked inside
        // that partition. ROW_NUMBER over one pass, rather than a LATERAL sub-select
        // per point: the radius filter costs a full scan (no index on
        // `geom::geography`), so a per-point sub-select would repeat it per stage.
        //
        // The assignment casts to `geography` on purpose: `<->` on `geometry` is a
        // planar distance in raw WGS84 degrees, where a degree of longitude is
        // cos(latitude) shorter than a degree of latitude, so near the bisector of two
        // end points it can pick a different one than the metric
        // GeometryBasedDistributor::distributeByEndpoint uses downstream — leaving the
        // row ranked under a stage that will never receive it. `geography <->` is
        // metres on the sphere, i.e. the same great circle as HaversineDistance.
        //
        // The order is fully specified — end point, then distance, then the `id`
        // primary key (the DataTourisme URI) — so two runs of the same scan return
        // the same rows in the same order, the reproducibility ADR-040 promises.
        // `ranked.*` carries the ranking helper columns (point_index, distance,
        // point_rank, id); the mapping below reads named keys and ignores them.
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT ranked.*
                FROM (
                    SELECT a.id,
                           a.name, a.category, a.capacity, a.price, a.description, a.tags,
                           a.website, a.phone, a.opening_hours, a.wikidata, a.image_url, a.wikipedia_url,
                           ST_Y(a.geom) AS lat, ST_X(a.geom) AS lon,
                           nearest.point_index, nearest.distance,
                           ROW_NUMBER() OVER (
                               PARTITION BY nearest.point_index
                               ORDER BY nearest.distance, a.id
                           ) AS point_rank
                    FROM tourism.accommodations a
                    CROSS JOIN LATERAL (
                        SELECT pt.path[1] AS point_index,
                               a.geom::geography <-> pt.geom::geography AS distance
                        FROM ST_Dump(ST_SetSRID(ST_GeomFromText(:wkt), 4326)) AS pt
                        ORDER BY a.geom::geography <-> pt.geom::geography, pt.path
                        LIMIT 1
                    ) AS nearest
                    WHERE a.category IN (:categories)
                      AND ST_DWithin(
                          a.geom::geography,
                          ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography,
                          :radius
                      )
                ) AS ranked
                WHERE ranked.point_rank <= :limit
                ORDER BY ranked.point_index, ranked.distance, ranked.id
                SQL,
            [
                'wkt' => WktGeometry::multiPoint($points),
                'radius' => $radiusMeters,
                'categories' => $categories,
                'limit' => self::MAX_ROWS_PER_POINT,
            ],
            [
                'categories' => ArrayParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ],
        );

        $accommodations = [];
        foreach ($rows as $row) {
            $accommodations[] = [
                'name' => (string) $row['name'],
                'category' => (string) $row['category'],
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'capacity' => null !== $row['capacity'] ? (int) $row['capacity'] : null,
                'price' => null !== $row['price'] ? (float) $row['price'] : null,
                'description' => null !== $row['description'] && '' !== $row['description'] ? (string) $row['description'] : null,
                // Flux-set columns (#872); image_url / wikipedia_url come from the
                // provisioner's Wikidata pass, which now covers this table too.
                'website' => $this->text($row['website']),
                'phone' => $this->text($row['phone']),
                'openingHours' => $this->text($row['opening_hours']),
                'wikidata' => $this->text($row['wikidata']),
                'imageUrl' => $this->text($row['image_url']),
                'wikipediaUrl' => $this->text($row['wikipedia_url']),
                'tags' => $this->decodeTags($row['tags']),
            ];
        }

        return $accommodations;
    }

    private function text(string|int|float|bool|null $value): ?string
    {
        return null !== $value && '' !== $value ? (string) $value : null;
    }

    /**
     * The flux keys the provisioner preserved (DataTourismeMapper::tags), flattened
     * to the OSM-tag contract the consumers share: `opening_hours` is what
     * SeasonalityChecker reads, `website` what the completeness ranking scores.
     * List-valued keys (`type`, `labels`) stay in the jsonb and are not projected
     * here, that contract being string → string.
     *
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
