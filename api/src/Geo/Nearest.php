<?php

declare(strict_types=1);

namespace App\Geo;

use App\ApiResource\Model\Coordinate;

/**
 * Linear nearest-point scans over a handful of candidates, with the distance function
 * passed in so a caller keeps whatever {@see GeoDistanceInterface} it was given.
 *
 * Ties go to the first candidate, and "within" is strict, as in every scan this replaces.
 * The distance is always measured as (reference, candidate) for a point search and
 * (vertex, point) for a line search, the argument order those scans used.
 */
final class Nearest
{
    /**
     * The candidate closest to $from, or null when there is none.
     *
     * @template T of array{lat: float, lon: float, ...}
     *
     * @param list<T> $candidates
     *
     * @return T|null
     */
    public static function to(GeoDistanceInterface $distance, Coordinate $from, array $candidates): ?array
    {
        $minDistance = \PHP_FLOAT_MAX;
        $nearest = null;

        foreach ($candidates as $candidate) {
            $d = $distance->inMeters($from->lat, $from->lon, $candidate['lat'], $candidate['lon']);
            if ($d < $minDistance) {
                $minDistance = $d;
                $nearest = $candidate;
            }
        }

        return $nearest;
    }

    /**
     * Whether any candidate lies strictly closer than $radiusMeters to $from.
     *
     * @param list<array{lat: float, lon: float, ...}> $candidates
     */
    public static function anyWithin(GeoDistanceInterface $distance, Coordinate $from, array $candidates, float $radiusMeters): bool
    {
        return array_any(
            $candidates,
            static fn (array $candidate): bool => $distance->inMeters($from->lat, $from->lon, $candidate['lat'], $candidate['lon']) < $radiusMeters,
        );
    }

    /**
     * Index of the vertex of $line closest to the point, 0 for an empty line.
     *
     * @param list<Coordinate> $line
     */
    public static function vertexIndex(GeoDistanceInterface $distance, array $line, float $lat, float $lon): int
    {
        $minDistance = \PHP_FLOAT_MAX;
        $nearest = 0;

        foreach ($line as $i => $vertex) {
            $d = $distance->inMeters($vertex->lat, $vertex->lon, $lat, $lon);
            if ($d < $minDistance) {
                $minDistance = $d;
                $nearest = $i;
            }
        }

        return $nearest;
    }

    /**
     * Distance (m) from the point to the closest vertex of $line, PHP_FLOAT_MAX for an empty line.
     *
     * @param list<Coordinate> $line
     */
    public static function distanceToLine(GeoDistanceInterface $distance, array $line, float $lat, float $lon): float
    {
        $minDistance = \PHP_FLOAT_MAX;

        foreach ($line as $vertex) {
            $minDistance = min($minDistance, $distance->inMeters($vertex->lat, $vertex->lon, $lat, $lon));
        }

        return $minDistance;
    }
}
