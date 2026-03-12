<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Linear rider time estimator.
 *
 * Models a rider departing at $departureHour and pedalling for 10 hours.
 * The position at any given moment is interpolated proportionally to the distance.
 *
 * Formula: estimatedTime = departureHour + (distanceKm / totalDistanceKm) * 10.0
 */
final readonly class RiderTimeEstimator implements RiderTimeEstimatorInterface
{
    private const float PEDALLING_HOURS = 10.0;

    public function estimateTimeAtDistance(float $distanceKm, float $totalDistanceKm, int $departureHour = 8): float
    {
        if ($totalDistanceKm <= 0.0) {
            return (float) $departureHour;
        }

        $ratio = min(1.0, max(0.0, $distanceKm / $totalDistanceKm));

        return $departureHour + $ratio * self::PEDALLING_HOURS;
    }
}
