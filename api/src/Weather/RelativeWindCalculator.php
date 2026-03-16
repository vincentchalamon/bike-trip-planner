<?php

declare(strict_types=1);

namespace App\Weather;

/**
 * Pure functions for relative wind direction calculation.
 *
 * Meteorological convention: wind direction is where the wind comes FROM.
 * Cyclist convention: stage bearing is where the cyclist is heading TO.
 *
 * A diff of 0° means wind comes from the same direction the cyclist heads → headwind.
 * A diff of 180° means wind comes from directly behind → tailwind.
 */
final class RelativeWindCalculator
{
    /**
     * Converts a compass direction string (N, NE, E, SE, S, SO, O, NO)
     * to degrees (direction the wind comes FROM, 0 = North, clockwise).
     *
     * Returns null for unrecognised strings.
     */
    public function directionToDeg(string $direction): ?float
    {
        return match ($direction) {
            'N' => 0.0,
            'NE' => 45.0,
            'E' => 90.0,
            'SE' => 135.0,
            'S' => 180.0,
            'SO' => 225.0,
            'O' => 270.0,
            'NO' => 315.0,
            default => null,
        };
    }

    /**
     * Computes the true bearing (degrees, clockwise from North) from point A to point B.
     *
     * Returns null when the two points are practically identical (rest day / trivial stage).
     */
    public function computeBearing(float $lat1Deg, float $lon1Deg, float $lat2Deg, float $lon2Deg): ?float
    {
        $dist = abs($lat2Deg - $lat1Deg) + abs($lon2Deg - $lon1Deg);
        if ($dist < 1e-6) {
            return null;
        }

        $lat1 = deg2rad($lat1Deg);
        $lat2 = deg2rad($lat2Deg);
        $deltaLon = deg2rad($lon2Deg - $lon1Deg);

        $x = sin($deltaLon) * cos($lat2);
        $y = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($deltaLon);

        return fmod(rad2deg(atan2($x, $y)) + 360, 360);
    }

    /**
     * Classifies wind as headwind / tailwind / crosswind / unknown.
     *
     * diff = angular distance between wind-from direction and cyclist heading, normalised to [0, 180]:
     *   - 0°   → wind blows straight into cyclist's face → headwind
     *   - 180° → wind pushes from directly behind       → tailwind
     *   - ~90° → wind from the side                     → crosswind
     *
     * Thresholds: ≤ 60° headwind, ≥ 120° tailwind, else crosswind.
     *
     * @return 'headwind'|'tailwind'|'crosswind'|'unknown'
     */
    public function classify(string $windDirection, float $stageBearing): string
    {
        $windDeg = $this->directionToDeg($windDirection);
        if (null === $windDeg) {
            return 'unknown';
        }

        // Normalise angular difference to [0, 180]
        $diff = fmod(abs($windDeg - $stageBearing), 360);
        if ($diff > 180) {
            $diff = 360 - $diff;
        }

        // diff 0 = headwind (wind from ahead), diff 180 = tailwind (wind from behind)
        return match (true) {
            $diff <= 60 => 'headwind',
            $diff >= 120 => 'tailwind',
            default => 'crosswind',
        };
    }
}
