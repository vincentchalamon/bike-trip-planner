<?php

declare(strict_types=1);

namespace App\Engine;

use App\ApiResource\Model\Coordinate;
use Location\Coordinate as GeoCoordinate;
use Location\Distance\Vincenty;

/**
 * Geodesic distance calculator using the Vincenty inverse formula.
 *
 * Vincenty's formula computes the distance between two points on an oblate spheroid
 * (WGS-84 ellipsoid), providing sub-millimeter accuracy compared to the ~0.3% error
 * of the simpler Haversine formula. This precision matters for long bikepacking routes
 * where cumulative error can reach several kilometers.
 *
 * All public methods accept {@see Coordinate} DTOs and return distances in kilometers
 * (for track totals) or meters (for point-to-point measurements).
 */
final readonly class DistanceCalculator implements DistanceCalculatorInterface
{
    public function __construct(
        private Vincenty $vincenty = new Vincenty(),
    ) {
    }

    /**
     * Calculates the total distance of a track in kilometers using Vincenty formula.
     *
     * @param list<Coordinate> $points
     */
    public function calculateTotalDistance(array $points): float
    {
        if (\count($points) < 2) {
            return 0.0;
        }

        $totalMeters = 0.0;
        $counter = \count($points);

        for ($i = 1; $i < $counter; ++$i) {
            $from = new GeoCoordinate($points[$i - 1]->lat, $points[$i - 1]->lon);
            $to = new GeoCoordinate($points[$i]->lat, $points[$i]->lon);
            $totalMeters += $this->vincenty->getDistance($from, $to);
        }

        return $totalMeters / 1000.0;
    }

    /**
     * Calculates the straight-line distance between two coordinates in meters.
     */
    public function distanceBetween(Coordinate $from, Coordinate $to): float
    {
        $geoFrom = new GeoCoordinate($from->lat, $from->lon);
        $geoTo = new GeoCoordinate($to->lat, $to->lon);

        return $this->vincenty->getDistance($geoFrom, $geoTo);
    }

    /**
     * Walks along points from a given start index and splits at the target distance.
     *
     * Returns [firstSlice, secondSlice] where firstSlice has exactly targetKm
     * worth of points and secondSlice starts with the split point.
     *
     * @param list<Coordinate> $points
     * @param int              $startIndex Index in $points to start walking from
     * @param float            $targetKm   Distance in km to walk
     *
     * @return array{list<Coordinate>, list<Coordinate>, float} [stagePoints, remainingPoints, actualDistanceKm]
     */
    public function splitAtDistance(array $points, int $startIndex, float $targetKm): array
    {
        $accumulated = 0.0;
        $counter = \count($points);

        for ($i = $startIndex + 1; $i < $counter; ++$i) {
            $from = new GeoCoordinate($points[$i - 1]->lat, $points[$i - 1]->lon);
            $to = new GeoCoordinate($points[$i]->lat, $points[$i]->lon);
            $segment = $this->vincenty->getDistance($from, $to) / 1000.0;
            $accumulated += $segment;

            if ($accumulated >= $targetKm) {
                $first = \array_slice($points, $startIndex, $i - $startIndex + 1);
                $second = \array_slice($points, $i);

                return [$first, $second, $accumulated];
            }
        }

        // Target exceeds remaining distance: return all points from startIndex
        $first = \array_slice($points, $startIndex);

        return [$first, [], $accumulated];
    }

    /**
     * Finds the index in $points closest to the given coordinate.
     *
     * @param list<Coordinate> $points
     */
    public function findClosestIndex(array $points, Coordinate $target): int
    {
        $bestIndex = 0;
        $bestDistance = \PHP_FLOAT_MAX;
        $geoTarget = new GeoCoordinate($target->lat, $target->lon);

        foreach ($points as $i => $point) {
            $geo = new GeoCoordinate($point->lat, $point->lon);
            $d = $this->vincenty->getDistance($geo, $geoTarget);
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $bestIndex = $i;
            }
        }

        return $bestIndex;
    }
}
