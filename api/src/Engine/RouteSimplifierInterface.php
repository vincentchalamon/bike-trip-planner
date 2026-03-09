<?php

declare(strict_types=1);

namespace App\Engine;

use App\ApiResource\Model\Coordinate;

interface RouteSimplifierInterface
{
    /**
     * Simplifies a track using Douglas-Peucker algorithm preserving elevation.
     *
     * @param list<Coordinate> $points
     *
     * @return list<Coordinate>
     */
    public function simplify(array $points, float $tolerance = 20.0): array;
}
