<?php

declare(strict_types=1);

namespace App\Engine;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;

interface PacingEngineInterface
{
    /**
     * Generates stages from a decimated track using a fatigue-aware pacing formula.
     *
     * @param list<Coordinate>      $points             Decimated track points (geometry + splitting)
     * @param list<Coordinate>|null $rawPoints          Full-resolution points for accurate elevation calculation (falls back to $points when null)
     * @param float|null            $maxDistancePerDay  Optional cap on daily distance (km), applied after the pacing formula
     *
     * @return list<Stage>
     */
    public function generateStages(
        string $tripId,
        array $points,
        int $numberOfDays,
        float $totalDistanceKm,
        float $fatigueFactor = 0.9,
        float $elevationPenalty = 50.0,
        ?array $rawPoints = null,
        ?float $maxDistancePerDay = null,
    ): array;
}
