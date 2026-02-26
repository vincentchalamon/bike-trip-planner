<?php

declare(strict_types=1);

namespace App\Engine;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;

final class PacingEngineRegistry implements EngineInterface, EngineRegistryAwareInterface
{
    use EngineRegistryAwareTrait;

    private const float MINIMUM_STAGE_DISTANCE_KM = 30.0;

    /**
     * Generates stages from a decimated track using a fatigue-aware pacing formula.
     *
     * Formula: Dn = base * fatigueFactor^(n-1) - (D+ / elevationPenalty)
     * Minimum stage distance: 30km
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
    ): array {
        if ([] === $points || $numberOfDays <= 0) {
            return [];
        }

        // Compute target distances for each day
        $targets = $this->computeTargetDistances($numberOfDays, $totalDistanceKm, $points, $fatigueFactor, $elevationPenalty);

        return $this->sliceIntoStages($tripId, $points, $targets);
    }

    /**
     * @param list<Coordinate> $points
     *
     * @return list<float> Target distances per day in km
     */
    private function computeTargetDistances(
        int $numberOfDays,
        float $totalDistanceKm,
        array $points,
        float $fatigueFactor,
        float $elevationPenalty,
    ): array {
        $elevation = $this->getEngine(ElevationCalculator::class)->calculateTotalAscent($points);

        // Base target from total distance (to be adjusted by fatigue)
        // Sum of geometric series: base * (1 + f + f^2 + ... + f^(n-1)) = totalDistance
        // base = totalDistance / ((1 - f^n) / (1 - f))  if f != 1
        $sumFactors = 0.0;
        for ($i = 0; $i < $numberOfDays; ++$i) {
            $sumFactors += $fatigueFactor ** $i;
        }

        $elevationPenaltyPerDay = ($elevation / $elevationPenalty) / $numberOfDays;
        $adjustedTotal = $totalDistanceKm + ($elevationPenaltyPerDay * $numberOfDays);
        $base = $sumFactors > 0.0 ? $adjustedTotal / $sumFactors : $totalDistanceKm / $numberOfDays;

        $targets = [];
        for ($day = 0; $day < $numberOfDays; ++$day) {
            $target = $base * ($fatigueFactor ** $day) - $elevationPenaltyPerDay;
            $targets[] = max(self::MINIMUM_STAGE_DISTANCE_KM, $target);
        }

        return $targets;
    }

    /**
     * @param list<Coordinate> $points
     * @param list<float>      $targets
     *
     * @return list<Stage>
     */
    private function sliceIntoStages(string $tripId, array $points, array $targets): array
    {
        $stages = [];
        $remaining = $points;
        $dayNumber = 1;

        foreach ($targets as $i => $targetKm) {
            if (\count($remaining) < 2) {
                break;
            }

            // Last stage gets all remaining points
            $isLastDay = $i === \count($targets) - 1;

            if ($isLastDay) {
                $stagePoints = $remaining;
                $remaining = [];
            } else {
                [$stagePoints, $remaining] = $this->splitAtDistance($remaining, $targetKm);
            }

            if (\count($stagePoints) < 2) {
                // Absorb into remaining
                $remaining = array_merge($stagePoints, $remaining);
                continue;
            }

            $distance = $this->getEngine(DistanceCalculator::class)->calculateTotalDistance($stagePoints);
            $elevation = $this->getEngine(ElevationCalculator::class)->calculateTotalAscent($stagePoints);
            $geometry = $this->getEngine(RouteSimplifier::class)->simplify($stagePoints);

            $stages[] = new Stage(
                tripId: $tripId,
                dayNumber: $dayNumber,
                distance: $distance,
                elevation: $elevation,
                startPoint: $stagePoints[0],
                endPoint: $stagePoints[\count($stagePoints) - 1],
                geometry: $geometry,
            );

            ++$dayNumber;
        }

        // If there are remaining points, add them to the last stage
        if ([] !== $remaining && [] !== $stages) {
            $lastStage = $stages[\count($stages) - 1];
            $lastStage->distance += $this->getEngine(DistanceCalculator::class)->calculateTotalDistance($remaining);
            $lastStage->elevation += $this->getEngine(ElevationCalculator::class)->calculateTotalAscent($remaining);
            $lastStage->endPoint = $remaining[\count($remaining) - 1];
        }

        return $stages;
    }

    /**
     * Splits points array at the point closest to targetKm from the start.
     *
     * @param list<Coordinate> $points
     *
     * @return array{list<Coordinate>, list<Coordinate>}
     */
    private function splitAtDistance(array $points, float $targetKm): array
    {
        $accumulated = 0.0;
        $counter = \count($points);

        for ($i = 1; $i < $counter; ++$i) {
            $segment = $this->getEngine(DistanceCalculator::class)->calculateTotalDistance([$points[$i - 1], $points[$i]]);
            $accumulated += $segment;

            if ($accumulated >= $targetKm) {
                $first = \array_slice($points, 0, $i + 1);
                $second = \array_slice($points, $i);

                return [$first, $second];
            }
        }

        // Target exceeds total: return all points, empty remainder
        return [$points, []];
    }
}
