<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Estimates the clock time (decimal hours) at which a rider passes a given distance marker.
 *
 * Uses a speed-based model: departure at $departureHour, effective speed derived from
 * base speed adjusted by elevation gain (Naismith adaptation for cycling).
 */
interface RiderTimeEstimatorInterface
{
    /**
     * Estimates the decimal hour (e.g. 13.5 = 13:30) at which the rider
     * reaches $distanceKm along a stage of $totalDistanceKm.
     *
     * @param float $distanceKm       Distance from stage start (km)
     * @param float $totalDistanceKm  Total stage distance (km)
     * @param int   $departureHour    Hour of departure (0-23, default 8)
     * @param float $averageSpeedKmh  Base cycling speed in km/h (default 15)
     * @param float $elevationGainM   Total elevation gain for the stage in metres (default 0)
     */
    public function estimateTimeAtDistance(
        float $distanceKm,
        float $totalDistanceKm,
        int $departureHour = 8,
        float $averageSpeedKmh = 15.0,
        float $elevationGainM = 0.0,
    ): float;

    /**
     * Estimates the total riding duration (decimal hours) for a stage.
     *
     * @param float $distanceKm      Total stage distance (km)
     * @param float $averageSpeedKmh Base cycling speed in km/h (default 15)
     * @param float $elevationGainM  Total elevation gain for the stage in metres (default 0)
     */
    public function estimateRidingDuration(
        float $distanceKm,
        float $averageSpeedKmh = 15.0,
        float $elevationGainM = 0.0,
    ): float;
}
