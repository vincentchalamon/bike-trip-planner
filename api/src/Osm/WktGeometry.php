<?php

declare(strict_types=1);

namespace App\Osm;

/**
 * Builds WKT geometries (lon-lat order) for the local-first PostGIS corridor
 * queries (ADR-040). WKT uses X Y = longitude latitude.
 */
final class WktGeometry
{
    /**
     * The decoded route as a LINESTRING, or a POINT when fewer than two distinct
     * points are available (ST_GeomFromText rejects a single-vertex LINESTRING).
     *
     * @param list<array{lat: float, lon: float}> $points non-empty
     *
     * @throws \InvalidArgumentException when $points is empty
     */
    public static function lineStringOrPoint(array $points): string
    {
        $coords = array_values(array_unique(array_map(
            static fn (array $p): string => \sprintf('%F %F', $p['lon'], $p['lat']),
            $points,
        )));

        if ([] === $coords) {
            throw new \InvalidArgumentException('lineStringOrPoint() requires at least one point.');
        }

        if (\count($coords) < 2) {
            return \sprintf('POINT(%s)', $coords[0]);
        }

        return \sprintf('LINESTRING(%s)', implode(',', $coords));
    }

    /**
     * @param list<array{lat: float, lon: float}> $points non-empty
     *
     * @throws \InvalidArgumentException when $points is empty
     */
    public static function multiPoint(array $points): string
    {
        $coords = array_values(array_unique(array_map(
            static fn (array $p): string => \sprintf('(%F %F)', $p['lon'], $p['lat']),
            $points,
        )));

        if ([] === $coords) {
            throw new \InvalidArgumentException('multiPoint() requires at least one point.');
        }

        return \sprintf('MULTIPOINT(%s)', implode(',', $coords));
    }
}
