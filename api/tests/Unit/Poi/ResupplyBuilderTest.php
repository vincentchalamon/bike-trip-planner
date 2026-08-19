<?php

declare(strict_types=1);

namespace App\Tests\Unit\Poi;

use App\ApiResource\Model\PointOfInterest;
use App\Poi\ResupplyBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResupplyBuilderTest extends TestCase
{
    /**
     * @return array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}
     */
    private function entry(float $distance, string $category = 'restaurant', ?string $name = 'Chez X'): array
    {
        // lat carries the distance so every entry has a distinct coordinate.
        return ['name' => $name, 'category' => $category, 'lat' => $distance, 'lon' => 0.0, 'distanceFromStart' => $distance];
    }

    #[Test]
    public function itPicksTwoFoodAtLunchOneWaterEachSideTwoFoodAtArrival(): void
    {
        $food = [$this->entry(10), $this->entry(48), $this->entry(52), $this->entry(95), $this->entry(98)];
        $water = [$this->entry(5, 'water'), $this->entry(20, 'water'), $this->entry(70, 'water')];

        $pois = new ResupplyBuilder()->select($food, $water, lunchKm: 50, totalKm: 100, waterLabel: 'Eau');

        $distances = array_map(static fn (PointOfInterest $p): ?float => $p->distanceFromStart, $pois);
        // 2 food nearest 50 (48,52), water nearest 25 in [0,50] (20), water nearest
        // 75 in [50,100] (70), 2 food nearest 100 (98,95).
        self::assertSame([48.0, 52.0, 20.0, 70.0, 98.0, 95.0], $distances);
    }

    #[Test]
    public function itDeduplicatesWhenLunchCoincidesWithArrival(): void
    {
        $food = [$this->entry(2), $this->entry(18)];

        // Short stage: the rider reaches lunch at the arrival, so the lunch and
        // arrival food picks are the same two shops — deduped to two, not four.
        $pois = new ResupplyBuilder()->select($food, [], lunchKm: 20, totalKm: 20, waterLabel: 'Eau');

        self::assertCount(2, $pois);
        self::assertSame([18.0, 2.0], array_map(static fn (PointOfInterest $p): ?float => $p->distanceFromStart, $pois));
    }

    #[Test]
    public function itFallsBackToTheWaterLabelForAnUnnamedWaterPoint(): void
    {
        $water = [$this->entry(30, 'water', null)];

        $pois = new ResupplyBuilder()->select([], $water, lunchKm: 60, totalKm: 100, waterLabel: 'Point d’eau');

        self::assertCount(1, $pois);
        self::assertSame('Point d’eau', $pois[0]->name);
        self::assertSame('water', $pois[0]->category);
    }

    #[Test]
    public function itReturnsWhatIsAvailableBelowSixAndEmptyWhenNothing(): void
    {
        self::assertSame([], new ResupplyBuilder()->select([], [], lunchKm: 50, totalKm: 100, waterLabel: 'Eau'));

        // One food shop only: it satisfies both the lunch and arrival picks, deduped.
        $pois = new ResupplyBuilder()->select([$this->entry(40)], [], lunchKm: 50, totalKm: 100, waterLabel: 'Eau');
        self::assertCount(1, $pois);
    }
}
