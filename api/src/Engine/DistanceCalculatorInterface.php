<?php

declare(strict_types=1);

namespace App\Engine;

use App\ApiResource\Model\Coordinate;

interface DistanceCalculatorInterface extends EngineInterface
{
    /**
     * Calculates the total distance of a track in kilometers.
     *
     * @param list<Coordinate> $points
     */
    public function calculateTotalDistance(array $points): float;

    /**
     * Calculates the straight-line distance between two coordinates in meters.
     */
    public function distanceBetween(Coordinate $from, Coordinate $to): float;

    /**
     * Walks along points from a given start index and splits at the target distance.
     *
     * @param list<Coordinate> $points
     * @param int              $startIndex Index in $points to start walking from
     * @param float            $targetKm   Distance in km to walk
     *
     * @return array{list<Coordinate>, list<Coordinate>, float} [stagePoints, remainingPoints, actualDistanceKm]
     */
    public function splitAtDistance(array $points, int $startIndex, float $targetKm): array;

    /**
     * Finds the index in $points closest to the given coordinate.
     *
     * @param list<Coordinate> $points
     */
    public function findClosestIndex(array $points, Coordinate $target): int;
}
