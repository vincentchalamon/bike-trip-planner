<?php

declare(strict_types=1);

namespace App\Engine;

use App\ApiResource\Model\Coordinate;
use Location\Coordinate as GeoCoordinate;
use Location\Distance\Vincenty;

final readonly class DistanceCalculator implements EngineInterface
{
    private Vincenty $vincenty;

    public function __construct()
    {
        $this->vincenty = new Vincenty();
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
}
