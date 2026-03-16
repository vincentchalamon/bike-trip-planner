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
    public function estimateTimeAtEndEqualsDepartureHourPlusRidingDuration(): void
    {
        // 100 km at 15 km/h with 0 elevation = 6.667 hours
        $result = $this->estimator->estimateTimeAtDistance(100.0, 100.0, 8, 15.0, 0.0);

        $this->assertEqualsWithDelta(8.0 + (100.0 / 15.0), $result, 0.001);
    }

    #[Test]
    public function estimateTimeAtHalfwayReturnsHalfRidingDuration(): void
    {
        $result = $this->estimator->estimateTimeAtDistance(50.0, 100.0, 8, 15.0, 0.0);

        // Half the total duration from departure
        $this->assertEqualsWithDelta(8.0 + (50.0 / 15.0), $result, 0.001);
    }

    #[Test]
    public function estimateTimeWithCustomDepartureHour(): void
    {
        $result = $this->estimator->estimateTimeAtDistance(50.0, 100.0, 6, 15.0, 0.0);

        $this->assertEqualsWithDelta(6.0 + (50.0 / 15.0), $result, 0.001);
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
        // Clamped to ratio 1.0
        $result = $this->estimator->estimateTimeAtDistance(150.0, 100.0, 8, 15.0, 0.0);

        $this->assertEqualsWithDelta(8.0 + (100.0 / 15.0), $result, 0.001);
    }

    #[Test]
    public function elevationPenaltyReducesEffectiveSpeed(): void
    {
        // 500m D+ → -2 km/h → effective speed = 15 - 2 = 13 km/h
        $result = $this->estimator->estimateTimeAtDistance(100.0, 100.0, 8, 15.0, 500.0);

        $this->assertEqualsWithDelta(8.0 + (100.0 / 13.0), $result, 0.001);
    }

    #[Test]
    public function heavyElevationPenaltyIsFlooredAtMinimumSpeed(): void
    {
        // Enormous D+ → would go below 5 km/h floor → clamped to 5 km/h
        $result = $this->estimator->estimateTimeAtDistance(100.0, 100.0, 8, 15.0, 100_000.0);

        $this->assertEqualsWithDelta(8.0 + (100.0 / 5.0), $result, 0.001);
    }

    /**
     * @return array<string, array{float, float, int, float, float, float}>
     */
    public static function provideEstimateTimeAtDistanceCases(): array
    {
        return [
            'quarter of route, no elevation' => [25.0, 100.0, 8, 15.0, 0.0, 8.0 + 25.0 / 15.0],
            '80km of 120km, 9h departure, no elevation' => [80.0, 120.0, 9, 15.0, 0.0, 9.0 + 80.0 / 15.0],
            '60km of 60km, 7h departure, 300m elevation' => [60.0, 60.0, 7, 15.0, 300.0, 7.0 + 60.0 / (15.0 - 2.0 * 0.6)],
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
