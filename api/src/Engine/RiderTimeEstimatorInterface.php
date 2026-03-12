<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Estimates the clock time (decimal hours) at which a rider passes a given distance marker.
 *
 * Uses a linear progression model: departure at $departureHour, 10 hours of pedalling.
 */
interface RiderTimeEstimatorInterface
{
    /**
     * Estimates the decimal hour (e.g. 13.5 = 13:30) at which the rider
     * reaches $distanceKm along a stage of $totalDistanceKm.
     */
    public function estimateTimeAtDistance(float $distanceKm, float $totalDistanceKm, int $departureHour = 8): float;
}
