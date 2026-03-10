<?php

declare(strict_types=1);

namespace App\Engine;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;

/**
 * Fatigue-aware pacing engine that splits a GPS track into daily stages.
 *
 * Uses a geometric series to distribute the total distance across N days,
 * with a configurable fatigue factor (default 0.9) that reduces each successive
 * day's target by 10%, and an elevation penalty that shortens stages proportionally
 * to the cumulative ascent. A minimum threshold (30 km) prevents unrealistically
 * short stages.
 *
 * Delegates distance measurement to {@see DistanceCalculatorInterface}, elevation
 * computation to {@see ElevationCalculatorInterface}, and geometry simplification
 * to {@see RouteSimplifierInterface}.
 *
 * @see docs/adr/adr-006-pacing-engine-and-dynamic-stage-generation-algorithm.md
 */
final readonly class PacingEngineRegistry implements PacingEngineInterface
{
    private const float MINIMUM_STAGE_DISTANCE_KM = 30.0;

    public function __construct(
        private DistanceCalculatorInterface $distanceCalculator,
        private ElevationCalculatorInterface $elevationCalculator,
        private RouteSimplifierInterface $routeSimplifier,
    ) {
    }

    /**
     * Generates stages from a decimated track using a fatigue-aware pacing formula.
     *
     * Formula: Dn = base * fatigueFactor^(n-1) - (D+ / elevationPenalty)
     * Minimum stage distance: 30km
     *
     * @param list<Coordinate>      $points    Decimated track points (geometry + splitting)
     * @param list<Coordinate>|null $rawPoints Full-resolution points for accurate elevation calculation (falls back to $points when null)
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
    ): array {
        if ([] === $points || $numberOfDays <= 0) {
            return [];
        }

        $elevationPoints = $rawPoints ?? $points;

        // Compute target distances for each day using raw points for accurate elevation
        $targets = $this->computeTargetDistances($numberOfDays, $totalDistanceKm, $elevationPoints, $fatigueFactor, $elevationPenalty);

        return $this->sliceIntoStages($tripId, $points, $targets, $rawPoints);
    }

    /**
     * Computes the target distance for each day using a fatigue-aware geometric series.
     *
     * Algorithm:
     * 1. Calculate total ascent (D+) from the track points.
     * 2. Derive an elevation penalty spread evenly across all days: penaltyPerDay = (D+ / elevationPenalty) / N.
     * 3. Compute the base daily distance from the geometric series sum:
     *    base = (totalDistance + penaltyPerDay * N) / sum(fatigueFactor^i, i=0..N-1)
     *    This ensures the sum of all daily targets equals the total distance after penalty subtraction.
     * 4. For each day n (0-indexed): target_n = base * fatigueFactor^n - penaltyPerDay
     * 5. Clamp each target to the minimum stage distance (30 km).
     *
     * The fatigueFactor (default 0.9) models cumulative rider fatigue: day 1 is the longest,
     * each subsequent day is ~10% shorter. The elevationPenalty (default 50) converts
     * meters of ascent into equivalent flat kilometers (e.g. 1000m D+ ≈ 20 km penalty).
     *
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
        $elevation = $this->elevationCalculator->calculateTotalAscent($points);

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
     * @param list<Coordinate>      $points    Decimated points for geometry/splitting
     * @param list<float>           $targets   Target distances per day in km
     * @param list<Coordinate>|null $rawPoints Raw points for accurate elevation calculation
     *
     * @return list<Stage>
     */
    private function sliceIntoStages(string $tripId, array $points, array $targets, ?array $rawPoints = null): array
    {
        $stages = [];
        $remaining = $points;
        $remainingRaw = $rawPoints;
        $dayNumber = 1;

        foreach ($targets as $i => $targetKm) {
            if (\count($remaining) < 2) {
                break;
            }

            // Last stage gets all remaining points
            $isLastDay = $i === \count($targets) - 1;

            $stageRawPoints = null;

            if ($isLastDay) {
                $stagePoints = $remaining;
                $stageRawPoints = $remainingRaw;
                $remaining = [];
                $remainingRaw = null;
            } else {
                [$stagePoints, $remaining] = $this->splitAtDistance($remaining, $targetKm);

                if (null !== $remainingRaw) {
                    [$stageRawPoints, $remainingRaw] = $this->splitAtDistance($remainingRaw, $targetKm);
                } else {
                    $stageRawPoints = null;
                }
            }

            if (\count($stagePoints) < 2) {
                // Absorb into remaining
                $remaining = array_merge($stagePoints, $remaining);
                if (null !== $stageRawPoints && null !== $remainingRaw) {
                    $remainingRaw = array_merge($stageRawPoints, $remainingRaw);
                }

                continue;
            }

            $elevationSource = $stageRawPoints ?? $stagePoints;

            $distance = $this->distanceCalculator->calculateTotalDistance($stagePoints);
            $elevation = $this->elevationCalculator->calculateTotalAscent($elevationSource);
            $elevationLoss = $this->elevationCalculator->calculateTotalDescent($elevationSource);
            $geometry = $this->routeSimplifier->simplify($stagePoints);

            $stages[] = new Stage(
                tripId: $tripId,
                dayNumber: $dayNumber,
                distance: $distance,
                elevation: $elevation,
                startPoint: $stagePoints[0],
                endPoint: $stagePoints[\count($stagePoints) - 1],
                geometry: $geometry,
                elevationLoss: $elevationLoss,
            );

            ++$dayNumber;
        }

        // If there are remaining points, add them to the last stage
        // @todo #89 SRP: split into PacingTargetCalculator + RouteSlicer + StageAssembler
        if ([] !== $remaining && [] !== $stages) {
            $lastStage = $stages[\count($stages) - 1];
            $remainingElevationSource = $remainingRaw ?? $remaining;
            $lastStage->distance += $this->distanceCalculator->calculateTotalDistance($remaining);
            $lastStage->elevation += $this->elevationCalculator->calculateTotalAscent($remainingElevationSource);
            $lastStage->elevationLoss += $this->elevationCalculator->calculateTotalDescent($remainingElevationSource);
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
            $segment = $this->distanceCalculator->calculateTotalDistance([$points[$i - 1], $points[$i]]);
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
