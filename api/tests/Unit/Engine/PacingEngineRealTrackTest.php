<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engine;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Engine\DistanceCalculator;
use App\Engine\ElevationCalculator;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\PacingEngine;
use App\Engine\RouteSimplifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the pacing engine on a realistic recording: a dense raw track with GPS jitter and
 * its Douglas-Peucker decimation through the real {@see RouteSimplifier}, as GPX upload does.
 *
 * The raw track is longer than the decimated one (jitter adds length), so the two must not be
 * split independently by accumulated distance: the raw slice used for a stage's elevation has
 * to cover the same stretch of road as the decimated slice used for its geometry.
 */
final class PacingEngineRealTrackTest extends TestCase
{
    private const float BOUNDARY_TOLERANCE_KM = 0.05;

    private DistanceCalculator $distanceCalculator;

    #[\Override]
    protected function setUp(): void
    {
        $this->distanceCalculator = new DistanceCalculator();
    }

    #[Test]
    public function rawStageBoundariesFollowTheDecimatedBoundaries(): void
    {
        $raw = $this->jitteryTrack(20_000);
        $decimated = new RouteSimplifier()->simplify($raw);

        [$stages, $rawSegments] = $this->generate($decimated, $raw, 4);

        $this->assertCount(4, $stages);

        $cumulative = [0.0];
        for ($i = 1, $n = \count($raw); $i < $n; ++$i) {
            $cumulative[] = $cumulative[$i - 1] + $this->distanceCalculator->distanceBetween($raw[$i - 1], $raw[$i]) / 1000.0;
        }

        $offsets = [];
        foreach ($stages as $i => $stage) {
            $rawEnd = $rawSegments[$i][\count($rawSegments[$i]) - 1];
            $offsets[] = round(
                $cumulative[$this->distanceCalculator->findClosestIndex($raw, $stage->endPoint)]
                - $cumulative[$this->distanceCalculator->findClosestIndex($raw, $rawEnd)],
                3,
            );
        }

        $this->assertSame(
            array_fill(0, 4, 0.0),
            array_map(static fn (float $o): float => abs($o) <= self::BOUNDARY_TOLERANCE_KM ? 0.0 : $o, $offsets),
            \sprintf(
                'Raw %.2f km vs decimated %.2f km (%d vs %d points); decimated minus raw stage end (km): %s',
                $cumulative[\count($cumulative) - 1],
                $this->distanceCalculator->calculateTotalDistance($decimated),
                \count($raw),
                \count($decimated),
                json_encode($offsets),
            ),
        );
    }

    #[Test]
    public function outAndBackStageEndIsNotTakenFromTheOutboundPass(): void
    {
        // 60 km out, then back over the very same points: stage 2 ends on the way back, on a
        // spot the outbound leg of stage 2 already went through.
        $outbound = $this->jitteryTrack(7_500);
        $raw = [...$outbound, ...\array_slice(array_reverse($outbound), 1)];
        $decimated = new RouteSimplifier()->simplify($raw);

        [$stages, $rawSegments] = $this->generate($decimated, $raw, 4);

        $this->assertCount(4, $stages);
        foreach ($stages as $i => $stage) {
            $ratio = $this->distanceCalculator->calculateTotalDistance($rawSegments[$i]) / $stage->distance;
            $this->assertEqualsWithDelta(1.0, $ratio, 0.05, \sprintf('Stage %d: raw slice / decimated distance = %.3f', $i + 1, $ratio));
        }
    }

    /**
     * @param list<Coordinate> $decimated
     * @param list<Coordinate> $raw
     *
     * @return array{list<Stage>, list<list<Coordinate>>} the stages, and the raw slice each one took its elevation from
     */
    private function generate(array $decimated, array $raw, int $days): array
    {
        $elevation = new class (new ElevationCalculator()) implements ElevationCalculatorInterface {
            /** @var list<list<Coordinate>> */
            public array $ascentInputs = [];

            public function __construct(private readonly ElevationCalculatorInterface $inner)
            {
            }

            public function calculateTotalAscent(array $points): float
            {
                $this->ascentInputs[] = $points;

                return $this->inner->calculateTotalAscent($points);
            }

            public function calculateTotalDescent(array $points): float
            {
                return $this->inner->calculateTotalDescent($points);
            }
        };

        $stages = new PacingEngine($this->distanceCalculator, $elevation, new RouteSimplifier())
            ->generateStages('trip-1', $decimated, $days, $this->distanceCalculator->calculateTotalDistance($raw), rawPoints: $raw);

        // The first ascent call is the whole track (pacing targets); the next ones are per stage.
        return [$stages, \array_slice($elevation->ascentInputs, 1)];
    }

    /**
     * Winding road sampled every ~8 m (20k points = ~160 km), with correlated (AR(1)) GPS noise of ~3 m and
     * rolling hills. Deterministic: seeded PRNG.
     *
     * @return list<Coordinate>
     */
    private function jitteryTrack(int $pointCount): array
    {
        mt_srand(42);
        $gauss = static fn (): float => sqrt(-2.0 * log(max(1e-12, mt_rand() / mt_getrandmax()))) * cos(2.0 * \M_PI * mt_rand() / mt_getrandmax());

        $metersPerDegLat = 111_320.0;
        $lat = 45.0;
        $lon = 5.0;
        $heading = 0.0;
        $noiseN = 0.0;
        $noiseE = 0.0;
        $phi = 0.9;
        $innovation = 3.0 * sqrt(1.0 - $phi * $phi);
        $points = [];

        for ($i = 0; $i < $pointCount; ++$i) {
            $heading += 0.02 * sin($i / 400.0) + 0.004 * $gauss();
            $lat += 8.0 * cos($heading) / $metersPerDegLat;
            $lon += 8.0 * sin($heading) / ($metersPerDegLat * cos(deg2rad($lat)));

            $noiseN = $phi * $noiseN + $innovation * $gauss();
            $noiseE = $phi * $noiseE + $innovation * $gauss();
            $ele = 300.0 + 150.0 * sin($i / 900.0) + 40.0 * sin($i / 130.0) + 1.5 * $gauss();

            $points[] = new Coordinate(
                $lat + $noiseN / $metersPerDegLat,
                $lon + $noiseE / ($metersPerDegLat * cos(deg2rad($lat))),
                $ele,
            );
        }

        return $points;
    }
}
