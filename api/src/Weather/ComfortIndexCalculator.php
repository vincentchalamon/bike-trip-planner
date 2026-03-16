<?php

declare(strict_types=1);

namespace App\Weather;

/**
 * Computes a cyclist comfort index [0–100] from daily weather parameters.
 *
 * Score starts at 100 and is penalised by:
 *  - High temperature (> 30 °C)  → up to -20 pts
 *  - Cold temperature (< 5 °C)   → up to -20 pts
 *  - High wind speed (> 20 km/h) → up to -30 pts
 *  - High humidity (> 80 %)      → up to -20 pts
 *  - Rain probability (> 20 %)   → up to -30 pts
 */
final class ComfortIndexCalculator
{
    public function compute(float $tempMax, float $windSpeed, int $humidity, int $precipProb): int
    {
        $score = 100;

        // Temperature penalties
        if ($tempMax > 30.0) {
            $score -= (int) min(20, ($tempMax - 30.0) * 2);
        } elseif ($tempMax < 5.0) {
            $score -= (int) min(20, (5.0 - $tempMax) * 2);
        }

        // Wind penalty
        if ($windSpeed > 20.0) {
            $score -= (int) min(30, ($windSpeed - 20.0) * 1.5);
        }

        // Humidity penalty
        if ($humidity > 80) {
            $score -= (int) min(20, ($humidity - 80) * 0.5);
        }

        // Precipitation probability penalty
        if ($precipProb > 20) {
            $score -= (int) min(30, ($precipProb - 20) * 0.5);
        }

        return max(0, $score);
    }
}
