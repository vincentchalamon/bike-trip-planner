<?php

declare(strict_types=1);

namespace App\Engine;

use App\ApiResource\Model\Coordinate;

final class ElevationCalculator implements ElevationCalculatorInterface
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
        $accumulatedGain = 0.0;
        $counter = \count($points);

        for ($i = 1; $i < $counter; ++$i) {
            $diff = $points[$i]->ele - $points[$i - 1]->ele;
            if ($diff > 0) {
                $accumulatedGain += $diff;
            } else {
                if ($accumulatedGain > self::NOISE_THRESHOLD_METERS) {
                    $totalAscent += $accumulatedGain;
                }

                $accumulatedGain = 0.0;
            }
        }

        // Flush final ascending segment
        if ($accumulatedGain > self::NOISE_THRESHOLD_METERS) {
            $totalAscent += $accumulatedGain;
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
        $accumulatedLoss = 0.0;
        $counter = \count($points);

        for ($i = 1; $i < $counter; ++$i) {
            $diff = $points[$i - 1]->ele - $points[$i]->ele;
            if ($diff > 0) {
                $accumulatedLoss += $diff;
            } else {
                if ($accumulatedLoss > self::NOISE_THRESHOLD_METERS) {
                    $totalDescent += $accumulatedLoss;
                }

                $accumulatedLoss = 0.0;
            }
        }

        // Flush final descending segment
        if ($accumulatedLoss > self::NOISE_THRESHOLD_METERS) {
            $totalDescent += $accumulatedLoss;
        }

        return $totalDescent;
    }
}
