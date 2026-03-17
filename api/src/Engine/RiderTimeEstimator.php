<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Naismith-adapted rider time estimator for cycling.
 *
 * Computes the effective cycling speed by applying an elevation penalty to the
 * base average speed: -2 km/h per 500m of elevation gain (Naismith rule adapted
 * for cyclists). A minimum effective speed floor of 5 km/h is enforced.
 *
 * Effective speed formula: max(5, baseSpeed - 2 * (elevationGainM / 500))
 *
 * Breaks are added on top of riding time:
 * - Short break: 10 min per 2 full riding hours
 * - Lunch break: 1 h if noon falls within the riding window (departureHour < 12 < departureHour + ridingDuration)
 *
 * The estimated passage time at any distance marker is then:
 *   totalDuration = ridingDuration + breakDuration
 *   estimatedTime = departureHour + (distanceKm / totalDistanceKm) * totalDuration
 */
final readonly class RiderTimeEstimator implements RiderTimeEstimatorInterface
{
    /** Minimum effective speed (km/h) to avoid division-by-zero and unrealistic values. */
    private const float MIN_EFFECTIVE_SPEED_KMH = 5.0;

    /** Elevation penalty: -2 km/h per 500m of ascent. */
    private const float ELEVATION_PENALTY_PER_500M = 2.0;

    public function estimateTimeAtDistance(
        float $distanceKm,
        float $totalDistanceKm,
        int $departureHour = 8,
        float $averageSpeedKmh = 15.0,
        float $elevationGainM = 0.0,
    ): float {
        if ($totalDistanceKm <= 0.0) {
            return (float) $departureHour;
        }

        $ratio = min(1.0, max(0.0, $distanceKm / $totalDistanceKm));
        $ridingDuration = $this->estimateRidingDuration($totalDistanceKm, $averageSpeedKmh, $elevationGainM);
        $breakDuration = $this->computeBreakDuration($ridingDuration, $departureHour);

        return $departureHour + $ratio * ($ridingDuration + $breakDuration);
    }

    public function estimateRidingDuration(
        float $distanceKm,
        float $averageSpeedKmh = 15.0,
        float $elevationGainM = 0.0,
    ): float {
        if ($distanceKm <= 0.0) {
            return 0.0;
        }

        $effectiveSpeed = $this->computeEffectiveSpeed($averageSpeedKmh, $elevationGainM);

        return $distanceKm / $effectiveSpeed;
    }

    /**
     * Computes total break duration for a stage.
     *
     * - Short break: 10 min per 2 full riding hours
     * - Lunch break: 1 h if noon falls within the riding window
     */
    private function computeBreakDuration(float $ridingDurationH, int $departureHour): float
    {
        $shortBreaks = floor($ridingDurationH / 2.0) * (10.0 / 60.0);
        $noonBreak = ($departureHour < 12 && $departureHour + $ridingDurationH > 12) ? 1.0 : 0.0;

        return $shortBreaks + $noonBreak;
    }

    /**
     * Computes effective cycling speed after applying the Naismith elevation penalty.
     *
     * Formula: max(MIN_FLOOR, baseSpeed - PENALTY_PER_500M * (elevationGainM / 500))
     */
    private function computeEffectiveSpeed(float $baseSpeedKmh, float $elevationGainM): float
    {
        $penalty = self::ELEVATION_PENALTY_PER_500M * ($elevationGainM / 500.0);
        $effective = $baseSpeedKmh - $penalty;

        return max(self::MIN_EFFECTIVE_SPEED_KMH, $effective);
    }
}
