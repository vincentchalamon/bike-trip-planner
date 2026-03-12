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

    #[Test]
    public function estimateTimeAtStartReturnesDepartureHour(): void
    {
        $result = $this->estimator->estimateTimeAtDistance(0.0, 100.0, 8);

        $this->assertSame(8.0, $result);
    }

    #[Test]
    public function estimateTimeAtEndReturnesDepartureHourPlusTenHours(): void
    {
        $result = $this->estimator->estimateTimeAtDistance(100.0, 100.0, 8);

        $this->assertSame(18.0, $result);
    }

    #[Test]
    public function estimateTimeAtHalfwayReturnesDepartureHourPlusFiveHours(): void
    {
        $result = $this->estimator->estimateTimeAtDistance(50.0, 100.0, 8);

        $this->assertSame(13.0, $result);
    }

    #[Test]
    public function estimateTimeWithCustomDepartureHour(): void
    {
        $result = $this->estimator->estimateTimeAtDistance(50.0, 100.0, 6);

        $this->assertSame(11.0, $result);
    }

    #[Test]
    public function estimateTimeWithZeroTotalDistanceReturnsDepartureHour(): void
    {
        $result = $this->estimator->estimateTimeAtDistance(0.0, 0.0, 8);

        $this->assertSame(8.0, $result);
    }

    #[Test]
    public function estimateTimeWithDistanceBeyondTotalIsClamped(): void
    {
        // Distance exceeding total should be clamped to 1.0 ratio
        $result = $this->estimator->estimateTimeAtDistance(150.0, 100.0, 8);

        $this->assertSame(18.0, $result);
    }

    /**
     * @return array<string, array{float, float, int, float}>
     */
    public static function provideEstimateTimeAtDistanceCases(): array
    {
        return [
            'quarter of route, 8h departure' => [25.0, 100.0, 8, 10.5],
            'three quarters of route, 7h departure' => [75.0, 100.0, 7, 14.5],
            '80km of 120km, 9h departure' => [80.0, 120.0, 9, 15.667],
        ];
    }

    #[Test]
    #[DataProvider('provideEstimateTimeAtDistanceCases')]
    public function estimateTimeAtDistanceProducesCorrectDecimalHour(
        float $distanceKm,
        float $totalDistanceKm,
        int $departureHour,
        float $expectedHour,
    ): void {
        $result = $this->estimator->estimateTimeAtDistance($distanceKm, $totalDistanceKm, $departureHour);

        $this->assertEqualsWithDelta($expectedHour, $result, 0.01);
    }
}
