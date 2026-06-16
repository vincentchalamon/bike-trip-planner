<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Tests a route against the local-first coverage polygon (ADR-040): the single-row
 * osm.coverage table holds the union of the provisioned countries' admin_level=2
 * boundaries, materialised by the provisioner. A route not fully covered cannot be
 * (re)routed by Valhalla (no tiles out of zone), so the frontend renders it
 * display-only.
 *
 * Coverage is treated as "unknown" (never out of zone) when the polygon was never
 * provisioned (empty table or NULL geom), so a missing index never blocks the user.
 */
final readonly class CoverageRepository implements CoverageRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function isRouteOutOfZone(array $points): bool
    {
        if ([] === $points) {
            return false;
        }

        $result = $this->connection->fetchOne(
            <<<'SQL'
                SELECT (geom IS NOT NULL AND NOT ST_Covers(geom, ST_SetSRID(ST_GeomFromText(:wkt), 4326)))::int
                FROM osm.coverage
                LIMIT 1
                SQL,
            ['wkt' => $this->toWkt($points)],
        );

        return \in_array($result, [1, '1', true], true);
    }

    /**
     * @param non-empty-list<array{lat: float, lon: float}> $points
     */
    private function toWkt(array $points): string
    {
        $coords = array_map(
            static fn (array $point): string => \sprintf('%.7F %.7F', $point['lon'], $point['lat']),
            $points,
        );

        if (1 === \count($coords)) {
            return \sprintf('POINT(%s)', $coords[0]);
        }

        return \sprintf('LINESTRING(%s)', implode(', ', $coords));
    }
}
