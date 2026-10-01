<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engine;

use App\Engine\RiderTimeEstimatorInterface;

/**
 * A rider who passes every point of a stage at the same clock time.
 *
 * Its inverse follows: once that time has passed the whole stage is behind the rider, and
 * until then none of it is.
 */
final readonly class FixedPassageTimeEstimator implements RiderTimeEstimatorInterface
{
    public function __construct(private float $passageHour = 0.0)
    {
    }

    #[\Override]
    public function estimateTimeAtDistance(float $distanceKm, float $totalDistanceKm, int $departureHour = 8, float $averageSpeedKmh = 15.0, float $elevationGainM = 0.0): float
    {
        return $this->passageHour;
    }

    #[\Override]
    public function distanceAtHour(float $hour, float $totalDistanceKm, int $departureHour = 8, float $averageSpeedKmh = 15.0, float $elevationGainM = 0.0): float
    {
        return $this->passageHour < $hour ? max(0.0, $totalDistanceKm) : 0.0;
    }
}
