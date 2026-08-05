<?php

declare(strict_types=1);

namespace App\Osm;

use Doctrine\DBAL\Connection;

/**
 * Tests a route against the local-first coverage polygon (ADR-040/049): the single-row
 * osm.coverage table holds the union of the **opened zones'** geometries, materialised by
 * the provisioner from the osm.zones registry inside the promotion transaction. A route
 * not fully covered cannot be (re)routed by Valhalla (no tiles out of zone), so the
 * frontend renders it display-only.
 *
 * "Out of zone" therefore now means "zone not yet opened", which is a statement that can
 * be true. It used to be the union of whatever administrative boundaries happened to
 * survive in the imported extract — and since Geofabrik extracts are clipped, filtering
 * that union on admin_level = 2 produced a single NULL row on a regional set, i.e. no
 * coverage at all (#880). Each zone's registry geometry is the union of every level its
 * own extract yielded, so a missing coarse boundary no longer erases the zone.
 *
 * Coverage is still treated as "unknown" (never out of zone) when no zone has been
 * opened yet (empty table or NULL geom), so an unprovisioned index never blocks the user.
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
