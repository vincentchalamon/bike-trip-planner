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
     * @param list<Coordinate> $points Decimated track points
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
    ): array;
}
