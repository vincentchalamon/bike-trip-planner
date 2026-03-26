<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engine;

use App\Engine\RiderTimeEstimator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RiderTimeEstimatorTest extends TestCase
{
    private RiderTimeEstimator $estimator;

    #[\Override]
    protected function setUp(): void
    {
        $this->estimator = new RiderTimeEstimator();
    }

    // ----- estimateTimeAtDistance -----

    #[Test]
    public function estimateTimeAtStartReturnesDepartureHour(): void
    {
        $result = $this->estimator->estimateTimeAtDistance(0.0, 100.0, 8, 15.0, 0.0);

        $this->assertSame(8.0, $result);
    }

    #[Test]
    public function estimateTimeAtEndIncludesBreaks(): void
    {
        // 100 km at 15 km/h, dep 8h → riding 6.667h
        // short breaks: floor(6.667/2)=3 × 10/60 = 0.5h ; noon break: 8+6.667>12 → +1h
        // total = 8.167h → arrival at 16.167h
        $result = $this->estimator->estimateTimeAtDistance(100.0, 100.0, 8, 15.0, 0.0);

        $ridingDuration = 100.0 / 15.0;
        $breaks = 3 * (10.0 / 60.0) + 1.0;
        $this->assertEqualsWithDelta(8.0 + $ridingDuration + $breaks, $result, 0.01);
    }

    #[Test]
    public function estimateTimeAtHalfwayProportional(): void
    {
        // Same stage as above, at halfway the proportional share of total duration is applied
        $result = $this->estimator->estimateTimeAtDistance(50.0, 100.0, 8, 15.0, 0.0);

        $ridingDuration = 100.0 / 15.0;
        $breaks = 3 * (10.0 / 60.0) + 1.0;
        $totalDuration = $ridingDuration + $breaks;
        $this->assertEqualsWithDelta(8.0 + 0.5 * $totalDuration, $result, 0.01);
    }

    #[Test]
    public function estimateTimeWithCustomDepartureHour(): void
    {
        // dep 6h, 100km at 15km/h → riding 6.667h; short breaks 0.5h; noon: 6+6.667>12 → +1h
        $result = $this->estimator->estimateTimeAtDistance(50.0, 100.0, 6, 15.0, 0.0);

        $ridingDuration = 100.0 / 15.0;
        $breaks = 3 * (10.0 / 60.0) + 1.0;
        $totalDuration = $ridingDuration + $breaks;
        $this->assertEqualsWithDelta(6.0 + 0.5 * $totalDuration, $result, 0.01);
    }

    #[Test]
    public function estimateTimeWithZeroTotalDistanceReturnsDepartureHour(): void
    {
        $result = $this->estimator->estimateTimeAtDistance(0.0, 0.0, 8, 15.0, 0.0);

        $this->assertSame(8.0, $result);
    }

    #[Test]
    public function estimateTimeWithDistanceBeyondTotalIsClamped(): void
    {
        // Clamped to ratio 1.0 — same result as full distance
        $result = $this->estimator->estimateTimeAtDistance(150.0, 100.0, 8, 15.0, 0.0);

        $ridingDuration = 100.0 / 15.0;
        $breaks = 3 * (10.0 / 60.0) + 1.0;
        $this->assertEqualsWithDelta(8.0 + $ridingDuration + $breaks, $result, 0.01);
    }

    #[Test]
    public function elevationPenaltyReducesEffectiveSpeed(): void
    {
        // 500m D+ → effective 13 km/h → riding 7.692h; short: 3×10/60=0.5h; noon: +1h
        $result = $this->estimator->estimateTimeAtDistance(100.0, 100.0, 8, 15.0, 500.0);

        $ridingDuration = 100.0 / 13.0;
        $breaks = 3 * (10.0 / 60.0) + 1.0;
        $this->assertEqualsWithDelta(8.0 + $ridingDuration + $breaks, $result, 0.01);
    }

    #[Test]
    public function heavyElevationPenaltyIsFlooredAtMinimumSpeed(): void
    {
        // Enormous D+ → 5 km/h; riding 20h; short: 10×10/60=1.667h; noon: +1h
        $result = $this->estimator->estimateTimeAtDistance(100.0, 100.0, 8, 15.0, 100_000.0);

        $ridingDuration = 100.0 / 5.0;
        $breaks = 10 * (10.0 / 60.0) + 1.0;
        $this->assertEqualsWithDelta(8.0 + $ridingDuration + $breaks, $result, 0.01);
    }

    /**
     * @return array<string, array{float, float, int, float, float, float}>
     */
    public static function provideEstimateTimeAtDistanceCases(): array
    {
        // riding=6.667h; short=3×10/60=0.5h; noon 8+6.667>12 → +1h; total=8.167h; at ratio=0.25
        $quarterNoElev = 8.0 + 0.25 * (100.0 / 15.0 + 3 * (10.0 / 60.0) + 1.0);

        // riding=8h; short=4×10/60=0.667h; noon 9+8>12 → +1h; total=9.667h; at ratio=80/120
        $eightyOf120 = 9.0 + (80.0 / 120.0) * (120.0 / 15.0 + 4 * (10.0 / 60.0) + 1.0);

        // effective=13.8km/h; riding=60/13.8=4.348h; short=2×10/60=0.333h; noon 7+4.348<12 → no lunch
        $sixtyWith300mElev = 7.0 + 60.0 / (15.0 - 2.0 * 0.6) + 2 * (10.0 / 60.0);

        return [
            'quarter of route, no elevation' => [25.0, 100.0, 8, 15.0, 0.0, $quarterNoElev],
            '80km of 120km, 9h departure, no elevation' => [80.0, 120.0, 9, 15.0, 0.0, $eightyOf120],
            '60km of 60km, 7h departure, 300m elevation' => [60.0, 60.0, 7, 15.0, 300.0, $sixtyWith300mElev],
        ];
    }

    #[Test]
    #[DataProvider('provideEstimateTimeAtDistanceCases')]
    public function estimateTimeAtDistanceProducesCorrectDecimalHour(
        float $distanceKm,
        float $totalDistanceKm,
        int $departureHour,
        float $averageSpeedKmh,
        float $elevationGainM,
        float $expectedHour,
    ): void {
        $result = $this->estimator->estimateTimeAtDistance($distanceKm, $totalDistanceKm, $departureHour, $averageSpeedKmh, $elevationGainM);

        $this->assertEqualsWithDelta($expectedHour, $result, 0.01);
    }

    // ----- break logic -----

    #[Test]
    public function lateDepartureSkipsNoonBreak(): void
    {
        // Departure at 17h → noon already passed → no lunch break; riding 30/15=2h; short: 1×10/60
        $result = $this->estimator->estimateTimeAtDistance(30.0, 30.0, 17, 15.0, 0.0);

        $ridingDuration = 30.0 / 15.0;
        $shortBreaks = (10.0 / 60.0);
        $this->assertEqualsWithDelta(17.0 + $ridingDuration + $shortBreaks, $result, 0.01);
    }

    #[Test]
    public function shortStageLessThanTwoHoursHasNoBreak(): void
    {
        // 20km at 15km/h = 1.333h → no short break (< 2h); no noon break (arrives before noon)
        $result = $this->estimator->estimateTimeAtDistance(20.0, 20.0, 13, 15.0, 0.0);

        $ridingDuration = 20.0 / 15.0;
        $this->assertEqualsWithDelta(13.0 + $ridingDuration, $result, 0.01);
    }

    #[Test]
    public function noonBreakAppliesWhenNoonFallsInRidingWindow(): void
    {
        // Departure 10h, 50km at 15km/h = 3.333h → arrival 13.333h; noon is in [10, 13.333]
        $result = $this->estimator->estimateTimeAtDistance(50.0, 50.0, 10, 15.0, 0.0);

        $ridingDuration = 50.0 / 15.0;
        $shortBreaks = (10.0 / 60.0); // floor(3.333/2) = 1
        $noonBreak = 1.0;
        $this->assertEqualsWithDelta(10.0 + $ridingDuration + $shortBreaks + $noonBreak, $result, 0.01);
    }

    #[Test]
    public function noonBreakDoesNotApplyWhenDepartureAtNoon(): void
    {
        // Departure exactly at 12h → 12 is not < 12 → no noon break
        $result = $this->estimator->estimateTimeAtDistance(30.0, 30.0, 12, 15.0, 0.0);

        $ridingDuration = 30.0 / 15.0;
        $shortBreaks = (10.0 / 60.0);
        $this->assertEqualsWithDelta(12.0 + $ridingDuration + $shortBreaks, $result, 0.01);
    }

    // ----- estimateRidingDuration -----

    #[Test]
    public function ridingDurationWithZeroDistanceIsZero(): void
    {
        $result = $this->estimator->estimateRidingDuration(0.0, 15.0, 0.0);

        $this->assertSame(0.0, $result);
    }

    #[Test]
    public function ridingDurationWithNoElevation(): void
    {
        // 90 km at 15 km/h = 6 hours
        $result = $this->estimator->estimateRidingDuration(90.0, 15.0, 0.0);

        $this->assertEqualsWithDelta(6.0, $result, 0.001);
    }

    #[Test]
    public function ridingDurationWithElevationPenalty(): void
    {
        // 1000m D+ → -4 km/h → effective 11 km/h; 55 km / 11 = 5 hours
        $result = $this->estimator->estimateRidingDuration(55.0, 15.0, 1000.0);

        $this->assertEqualsWithDelta(5.0, $result, 0.001);
    }
}
