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
    public function itAnchorsFoodToLunchAndArrivalWaterToEachHalf(): void
    {
        $food = [$this->entry(10), $this->entry(48), $this->entry(52), $this->entry(95), $this->entry(98)];
        $water = [$this->entry(5, 'water'), $this->entry(20, 'water'), $this->entry(70, 'water')];

        $r = new ResupplyBuilder()->select($food, $water, lunchKm: 50, totalKm: 100, waterLabel: 'Eau');

        self::assertSame([48.0, 52.0], array_map(static fn (PointOfInterest $p): ?float => $p->distanceFromStart, $r->foodAtLunch));
        self::assertSame(20.0, $r->waterMorning?->distanceFromStart); // nearest 25 in [0,50]
        self::assertSame(70.0, $r->waterAfternoon?->distanceFromStart); // nearest 75 in [50,100]
        self::assertSame([98.0, 95.0], array_map(static fn (PointOfInterest $p): ?float => $p->distanceFromStart, $r->foodAtArrival));
    }

    #[Test]
    public function allFlattensAndDeduplicatesWhenLunchCoincidesWithArrival(): void
    {
        $food = [$this->entry(2), $this->entry(18)];

        // Short stage: lunch ≈ arrival, so both picks are the same two shops.
        $r = new ResupplyBuilder()->select($food, [], lunchKm: 20, totalKm: 20, waterLabel: 'Eau');

        self::assertSame([18.0, 2.0], array_map(static fn (PointOfInterest $p): ?float => $p->distanceFromStart, $r->foodAtLunch));
        self::assertSame([18.0, 2.0], array_map(static fn (PointOfInterest $p): ?float => $p->distanceFromStart, $r->foodAtArrival));
        // all() dedups by coordinate across roles.
        self::assertCount(2, $r->all());
    }

    #[Test]
    public function itFallsBackToTheWaterLabelForAnUnnamedWaterPoint(): void
    {
        $water = [$this->entry(30, 'water', null)];

        $r = new ResupplyBuilder()->select([], $water, lunchKm: 60, totalKm: 100, waterLabel: 'Point d’eau');

        self::assertNotNull($r->waterMorning);
        self::assertSame('Point d’eau', $r->waterMorning->name);
        self::assertSame('water', $r->waterMorning->category);
    }

    #[Test]
    public function itIsEmptyWhenThereIsNothingToSuggest(): void
    {
        $r = new ResupplyBuilder()->select([], [], lunchKm: 50, totalKm: 100, waterLabel: 'Eau');

        self::assertTrue($r->isEmpty());
        self::assertSame([], $r->all());
    }
}
