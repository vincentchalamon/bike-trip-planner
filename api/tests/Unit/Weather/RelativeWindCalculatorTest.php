<?php

declare(strict_types=1);

namespace App\Tests\Unit\Weather;

use Override;
use App\Weather\RelativeWindCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RelativeWindCalculatorTest extends TestCase
{
    private RelativeWindCalculator $calculator;

    #[Override]
    protected function setUp(): void
    {
        $this->calculator = new RelativeWindCalculator();
    }

    // ----- directionToDeg -----

    #[Test]
    public function directionToDegReturnsNullForUnknownDirection(): void
    {
        $this->assertNull($this->calculator->directionToDeg('X'));
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function provideDirections(): array
    {
        return [
            'N'  => ['N', 0.0],
            'NE' => ['NE', 45.0],
            'E'  => ['E', 90.0],
            'SE' => ['SE', 135.0],
            'S'  => ['S', 180.0],
            'SO' => ['SO', 225.0],
            'O'  => ['O', 270.0],
            'NO' => ['NO', 315.0],
        ];
    }

    #[Test]
    #[DataProvider('provideDirections')]
    public function directionToDegMapsAllCompassPoints(string $direction, float $expectedDeg): void
    {
        $this->assertSame($expectedDeg, $this->calculator->directionToDeg($direction));
    }

    // ----- computeBearing -----

    #[Test]
    public function computeBearingReturnsNullForIdenticalPoints(): void
    {
        $this->assertNull($this->calculator->computeBearing(48.85, 2.35, 48.85, 2.35));
    }

    #[Test]
    public function computeBearingEastIsApproximately90(): void
    {
        // Moving east along the equator: bearing should be ~90°
        $bearing = $this->calculator->computeBearing(0.0, 0.0, 0.0, 1.0);
        $this->assertNotNull($bearing);
        $this->assertEqualsWithDelta(90.0, $bearing, 0.5);
    }

    #[Test]
    public function computeBearingNorthIsApproximately0(): void
    {
        $bearing = $this->calculator->computeBearing(0.0, 0.0, 1.0, 0.0);
        $this->assertNotNull($bearing);
        $this->assertEqualsWithDelta(0.0, $bearing, 0.5);
    }

    #[Test]
    public function computeBearingSouthIsApproximately180(): void
    {
        $bearing = $this->calculator->computeBearing(1.0, 0.0, 0.0, 0.0);
        $this->assertNotNull($bearing);
        $this->assertEqualsWithDelta(180.0, $bearing, 0.5);
    }

    // ----- classify -----

    #[Test]
    public function classifyReturnsUnknownForUnrecognisedDirection(): void
    {
        $this->assertSame('unknown', $this->calculator->classify('X', 90.0));
    }

    #[Test]
    public function classifyPureHeadwind(): void
    {
        // Cyclist goes East (90°), wind comes from East (90°) → diff = 0 → headwind
        $this->assertSame('headwind', $this->calculator->classify('E', 90.0));
    }

    #[Test]
    public function classifyPureTailwind(): void
    {
        // Cyclist goes East (90°), wind comes from West (270°) → diff = 180 → tailwind
        $this->assertSame('tailwind', $this->calculator->classify('O', 90.0));
    }

    #[Test]
    public function classifyNorthHeadwindGoingNorth(): void
    {
        // Cyclist goes North (0°), wind from North (0°) → headwind
        $this->assertSame('headwind', $this->calculator->classify('N', 0.0));
    }

    #[Test]
    public function classifySouthTailwindGoingNorth(): void
    {
        // Cyclist goes North (0°), wind from South (180°) → tailwind
        $this->assertSame('tailwind', $this->calculator->classify('S', 0.0));
    }

    #[Test]
    public function classifyCrosswindFromRight(): void
    {
        // Cyclist goes North (0°), wind from East (90°) → diff = 90 → crosswind
        $this->assertSame('crosswind', $this->calculator->classify('E', 0.0));
    }

    #[Test]
    public function classifyCrosswindFromLeft(): void
    {
        // Cyclist goes North (0°), wind from West (270°) → diff = 90 → crosswind
        $this->assertSame('crosswind', $this->calculator->classify('O', 0.0));
    }

    #[Test]
    public function classifyBoundaryAt60IsHeadwind(): void
    {
        // Wind from NE (45°), cyclist goes East (90°) → diff = 45 → headwind (≤ 60)
        $this->assertSame('headwind', $this->calculator->classify('NE', 90.0));
    }

    #[Test]
    public function classifyBoundaryAt120IsTailwind(): void
    {
        // Cyclist goes North (0°), wind from SE (135°) → diff = 135 → tailwind (≥ 120)
        $this->assertSame('tailwind', $this->calculator->classify('SE', 0.0));
    }
}
