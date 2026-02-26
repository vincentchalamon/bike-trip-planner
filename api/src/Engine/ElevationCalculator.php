<?php

declare(strict_types=1);

namespace App\Engine;

use App\ApiResource\Model\Coordinate;

final class ElevationCalculator implements EngineInterface
{
    private const float NOISE_THRESHOLD_METERS = 3.0;

    /**
     * Calculates total ascent (D+) in meters, filtering noise below the threshold.
     *
     * @param list<Coordinate> $points
     */
    public function calculateTotalAscent(array $points): float
    {
        $totalAscent = 0.0;
        $counter = \count($points);

        for ($i = 1; $i < $counter; ++$i) {
            $diff = $points[$i]->ele - $points[$i - 1]->ele;
            if ($diff > self::NOISE_THRESHOLD_METERS) {
                $totalAscent += $diff;
            }
        }

        return $totalAscent;
    }

    /**
     * Calculates total descent (D-) in meters, filtering noise below the threshold.
     *
     * @param list<Coordinate> $points
     */
    public function calculateTotalDescent(array $points): float
    {
        $totalDescent = 0.0;
        $counter = \count($points);

        for ($i = 1; $i < $counter; ++$i) {
            $diff = $points[$i - 1]->ele - $points[$i]->ele;
            if ($diff > self::NOISE_THRESHOLD_METERS) {
                $totalDescent += $diff;
            }
        }

        return $totalDescent;
    }
}
