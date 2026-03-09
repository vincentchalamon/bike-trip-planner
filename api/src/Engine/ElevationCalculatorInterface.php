<?php

declare(strict_types=1);

namespace App\Engine;

use App\ApiResource\Model\Coordinate;

interface ElevationCalculatorInterface extends EngineInterface
{
    /**
     * Calculates total ascent (D+) in meters, filtering noise below the threshold.
     *
     * @param list<Coordinate> $points
     */
    public function calculateTotalAscent(array $points): float;

    /**
     * Calculates total descent (D-) in meters, filtering noise below the threshold.
     *
     * @param list<Coordinate> $points
     */
    public function calculateTotalDescent(array $points): float;
}
