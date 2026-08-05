<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Resolves the administrative units at a point from the local-first Tier-1 index
 * (ADR-040): ST_Covers lookups against osm.admin_boundaries, which holds the
 * country (admin_level=2), region (4), department (6) and commune (8) polygons.
 * Replaces the runtime Overpass `is_in` query for border-crossing detection and
 * the Nominatim reverse lookup for locality labels (#880).
 *
 * When several boundaries of the same level cover the same point (overlapping
 * polygons for a disputed territory, or a checkpoint exactly on a shared border),
 * the tie is resolved deterministically by osm_id so the trip snapshot stays
 * reproducible.
 */
final readonly class AdminBoundaryRepository implements AdminBoundaryRepositoryInterface
{
    /**
     * Lowest admin_level treated as a locality: the municipality is level 8 in
     * France and across most of Europe, so anything coarser (department, region)
     * is not a place name a rider would recognise as "where am I".
     */
    private const int LOCALITY_MIN_LEVEL = 7;

    public function __construct(private Connection $connection)
    {
    }

    public function findCountryAt(float $lat, float $lon, string $locale): ?string
    {
        $country = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COALESCE(tags->>('name:' || :locale), tags->>'name:en', name)
                FROM osm.admin_boundaries
                WHERE admin_level = 2
                  AND ST_Covers(geom, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
                ORDER BY osm_id
                LIMIT 1
                SQL,
            [
                'locale' => $locale,
                'lon' => $lon,
                'lat' => $lat,
            ],
        );

        if (\is_string($country) && '' !== $country) {
            return $country;
        }

        // No country polygon covers the point: on a clipped regional extract the
        // country relation is incomplete and never imported (see the provisioner's
        // measurements), so fall back to the ISO code carried by the sub-national
        // boundaries and localise it through ICU rather than reporting nothing.
        $code = $this->findCountryCodeAt($lat, $lon);
        if (null === $code) {
            return null;
        }

        $name = \Locale::getDisplayRegion('-'.$code, $locale);

        return \is_string($name) && '' !== $name ? $name : null;
    }

    public function findCountryCodeAt(float $lat, float $lon): ?string
    {
        // Coarsest boundary first: the country's own ISO 3166-1, then the ISO
        // 3166-2 of a region/department ("FR-59"), whose prefix is the country.
        // The code is computed in a subquery so covering boundaries carrying none
        // (communes) are filtered out rather than winning the LIMIT 1. Testing the
        // tags with the jsonb `?` operator is not an option: DBAL's SQL parser
        // reads it as a positional placeholder.
        $code = $this->connection->fetchOne(
            <<<'SQL'
                SELECT code FROM (
                    SELECT COALESCE(tags->>'ISO3166-1', tags->>'ISO3166-1:alpha2', left(tags->>'ISO3166-2', 2)) AS code,
                           admin_level,
                           osm_id
                    FROM osm.admin_boundaries
                    WHERE ST_Covers(geom, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
                ) covering
                WHERE code IS NOT NULL
                ORDER BY admin_level, osm_id
                LIMIT 1
                SQL,
            [
                'lon' => $lon,
                'lat' => $lat,
            ],
        );

        if (!\is_string($code) || 1 !== preg_match('/^[A-Za-z]{2}$/', $code)) {
            return null;
        }

        return strtoupper($code);
    }

    public function findLocalityAt(float $lat, float $lon, string $locale): ?string
    {
        $locality = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COALESCE(tags->>('name:' || :locale), tags->>'name:en', name)
                FROM osm.admin_boundaries
                WHERE admin_level >= :minLevel
                  AND ST_Covers(geom, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
                ORDER BY admin_level DESC, osm_id
                LIMIT 1
                SQL,
            [
                'locale' => $locale,
                'minLevel' => self::LOCALITY_MIN_LEVEL,
                'lon' => $lon,
                'lat' => $lat,
            ],
        );

        return \is_string($locality) && '' !== $locality ? $locality : null;
    }
}
