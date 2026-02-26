<?php

declare(strict_types=1);

namespace App\Engine;

use App\ApiResource\Model\Coordinate;
use Location\Coordinate as GeoCoordinate;
use Location\Distance\Vincenty;

final readonly class RouteSimplifier implements EngineInterface
{
    private const float TOLERANCE_METERS = 20.0;

    private Vincenty $vincenty;

    public function __construct()
    {
        $this->vincenty = new Vincenty();
    }

    /**
     * Simplifies a track using Douglas-Peucker algorithm preserving elevation.
     * Uses Vincenty distance for accuracy (~25k points → ~1.5k points with 20m tolerance).
     *
     * @param list<Coordinate> $points
     *
     * @return list<Coordinate>
     */
    public function simplify(array $points, float $tolerance = self::TOLERANCE_METERS): array
    {
        if (\count($points) <= 2) {
            return $points;
        }

        return $this->douglasPeucker($points, $tolerance);
    }

    /**
     * @param list<Coordinate> $points
     *
     * @return list<Coordinate>
     */
    private function douglasPeucker(array $points, float $tolerance): array
    {
        $count = \count($points);

        if ($count <= 2) {
            return $points;
        }

        $maxDistance = 0.0;
        $maxIndex = 0;
        $start = $points[0];
        $end = $points[$count - 1];

        for ($i = 1; $i < $count - 1; ++$i) {
            $distance = $this->perpendicularDistance($points[$i], $start, $end);
            if ($distance > $maxDistance) {
                $maxDistance = $distance;
                $maxIndex = $i;
            }
        }

        if ($maxDistance <= $tolerance) {
            return [$start, $end];
        }

        $leftPart = $this->douglasPeucker(\array_slice($points, 0, $maxIndex + 1), $tolerance);
        $rightPart = $this->douglasPeucker(\array_slice($points, $maxIndex), $tolerance);

        // Remove duplicate middle point
        array_pop($leftPart);

        return array_values(array_merge($leftPart, $rightPart));
    }

    /**
     * Calculates the perpendicular distance from a point to the line segment start-end.
     * Uses simplified cross-track distance approximation for performance.
     */
    private function perpendicularDistance(Coordinate $point, Coordinate $start, Coordinate $end): float
    {
        // Use direct Vincenty distances for a simplified cross-track calculation
        $geoStart = new GeoCoordinate($start->lat, $start->lon);
        $geoEnd = new GeoCoordinate($end->lat, $end->lon);
        $geoPoint = new GeoCoordinate($point->lat, $point->lon);

        $startToEnd = $this->vincenty->getDistance($geoStart, $geoEnd);

        if ($startToEnd < 0.001) {
            // Start and end are the same point
            return $this->vincenty->getDistance($geoStart, $geoPoint);
        }

        $startToPoint = $this->vincenty->getDistance($geoStart, $geoPoint);
        $endToPoint = $this->vincenty->getDistance($geoEnd, $geoPoint);

        // Heron's formula to compute area, then distance = 2*area / base
        $s = ($startToEnd + $startToPoint + $endToPoint) / 2.0;
        $areaSquared = $s * ($s - $startToEnd) * ($s - $startToPoint) * ($s - $endToPoint);

        if ($areaSquared <= 0.0) {
            return 0.0;
        }

        $area = sqrt($areaSquared);

        return (2.0 * $area) / $startToEnd;
    }
}
