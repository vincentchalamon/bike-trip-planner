<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads accommodations from the local-first Tier-1 index within a radius of the
 * stage end points (ST_DWithin), replacing the runtime Overpass accommodation
 * source (ADR-040). DataTourisme/Wikidata enrichment stays in their own sources.
 */
final readonly class AccommodationRepository implements AccommodationRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Accommodations of the given categories within $radiusMeters of any point.
     *
     * @param list<array{lat: float, lon: float}> $points
     * @param list<string>                        $categories
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float, stars: ?int, capacity: ?int, fee: ?string, website: ?string, wikidata: ?string, openingHours: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, tags: array<string, string>}>
     */
    public function findNear(array $points, int $radiusMeters, array $categories): array
    {
        if ([] === $points || [] === $categories) {
            return [];
        }

        // description / image_url / wikipedia_url are enriched from Wikidata at
        // provision time (ADR-041); website / opening_hours come from OSM tags.
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT name, category, stars, capacity, fee, website, wikidata, opening_hours,
                       description, image_url, wikipedia_url,
                       ST_Y(geom) AS lat, ST_X(geom) AS lon, tags
                FROM osm.accommodations
                WHERE category IN (:categories)
                  AND ST_DWithin(
                      geom::geography,
                      ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography,
                      :radius
                  )
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
