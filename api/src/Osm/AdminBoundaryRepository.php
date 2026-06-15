<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Resolves the country at a point from the local-first Tier-1 index (ADR-040):
 * a ST_Covers lookup against the admin_level=2 boundaries in osm.admin_boundaries,
 * replacing the runtime Overpass `is_in` query for border-crossing detection.
 */
final readonly class AdminBoundaryRepository implements AdminBoundaryRepositoryInterface
{
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
                LIMIT 1
                SQL,
            [
                'locale' => $locale,
                'lon' => $lon,
                'lat' => $lat,
            ],
        );

        return \is_string($country) && '' !== $country ? $country : null;
    }
}
