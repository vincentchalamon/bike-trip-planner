<?php

declare(strict_types=1);

namespace App\Tests\Unit\Weather;

use App\Weather\ComfortIndexCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComfortIndexCalculatorTest extends TestCase
{
    private ComfortIndexCalculator $calculator;

    #[\Override]
    protected function setUp(): void
    {
        $this->calculator = new ComfortIndexCalculator();
    }

    #[Test]
    public function perfectConditionsReturnFullScore(): void
    {
        // 20°C, no wind, 60% humidity, 0% rain → 100
        $this->assertSame(100, $this->calculator->compute(20.0, 0.0, 60, 0));
    }

    #[Test]
    public function hotTemperaturePenalisesScore(): void
    {
        // 40°C → penalty = min(20, (40 - 30) * 2) = 20 → 80
        $this->assertSame(80, $this->calculator->compute(40.0, 0.0, 60, 0));
    }

    #[Test]
    public function extremeHeatCapsAt20Penalty(): void
    {
        // 50°C → penalty = min(20, 40) = 20 → 80
        $this->assertSame(80, $this->calculator->compute(50.0, 0.0, 60, 0));
    }

    #[Test]
    public function coldTemperaturePenalisesScore(): void
    {
        // 0°C → penalty = min(20, (5 - 0) * 2) = 10 → 90
        $this->assertSame(90, $this->calculator->compute(0.0, 0.0, 60, 0));
    }

    #[Test]
    public function extremeColdCapsAt20Penalty(): void
    {
        // -10°C → penalty = min(20, 30) = 20 → 80
        $this->assertSame(80, $this->calculator->compute(-10.0, 0.0, 60, 0));
    }

    #[Test]
    public function highWindPenalisesScore(): void
    {
        // 30 km/h wind → penalty = min(30, (30 - 20) * 1.5) = 15 → 85
        $this->assertSame(85, $this->calculator->compute(20.0, 30.0, 60, 0));
    }

    #[Test]
    public function extremeWindCapsAt30Penalty(): void
    {
        // 50 km/h wind → penalty = min(30, 45) = 30 → 70
        $this->assertSame(70, $this->calculator->compute(20.0, 50.0, 60, 0));
    }

    #[Test]
    public function highHumidityPenalisesScore(): void
    {
        // 90% humidity → penalty = min(20, (90 - 80) * 0.5) = 5 → 95
        $this->assertSame(95, $this->calculator->compute(20.0, 0.0, 90, 0));
    }

    #[Test]
    public function extremeHumidityCapsAt20Penalty(): void
    {
        // 100% humidity → penalty = min(20, 10) = 10 → 90
        // 120% humidity → penalty = min(20, 20) = 20 → 80
        $this->assertSame(80, $this->calculator->compute(20.0, 0.0, 120, 0));
    }

    #[Test]
    public function rainProbabilityPenalisesScore(): void
    {
        // 60% rain → penalty = min(30, (60 - 20) * 0.5) = 20 → 80
        $this->assertSame(80, $this->calculator->compute(20.0, 0.0, 60, 60));
    }

    #[Test]
    public function extremeRainCapsAt30Penalty(): void
    {
        // 100% rain → penalty = min(30, 40) = 30 → 70
        $this->assertSame(70, $this->calculator->compute(20.0, 0.0, 60, 100));
    }

    #[Test]
    public function allPenaltiesCombinedNeverGoBelowZero(): void
    {
        // Worst possible conditions: 50°C, 50km/h, 120% humidity, 100% rain
        // Penalties: 20 + 30 + 20 + 30 = 100 → capped at max(0, 0) = 0
        $this->assertSame(0, $this->calculator->compute(50.0, 50.0, 120, 100));
    }

    /**
     * @return array<string, array{float, float, int, int, int}>
     */
    public static function provideBoundaryConditions(): array
    {
        return [
            'exactly 30°C is no penalty'  => [30.0, 0.0, 60, 0, 100],
            'exactly 5°C is no penalty'   => [5.0, 0.0, 60, 0, 100],
            'exactly 20 km/h is no penalty' => [20.0, 20.0, 60, 0, 100],
            'exactly 80% humidity is no penalty' => [20.0, 0.0, 80, 0, 100],
            'exactly 20% rain is no penalty' => [20.0, 0.0, 60, 20, 100],
        ];
    }

    #[Test]
    #[DataProvider('provideBoundaryConditions')]
    public function boundaryConditionsApplyNoPenalty(float $temp, float $wind, int $humidity, int $precip, int $expected): void
    {
        $this->assertSame($expected, $this->calculator->compute($temp, $wind, $humidity, $precip));
    }
}
