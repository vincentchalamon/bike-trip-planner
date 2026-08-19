<?php

declare(strict_types=1);

namespace App\Poi;

use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\Resupply;

/**
 * Curates a stage's thousands of corridor POIs down to a handful of resupply
 * *suggestions* (#1099): the raw set is a computation input, never a client
 * payload (it blocked the mobile trip-open parse). From the food + water points
 * already positioned along the route, it keeps:
 *
 *   - the 2 best food shops near the estimated lunch stop,
 *   - one water point mid-morning (before lunch) and one mid-afternoon (after),
 *   - the 2 best food shops at the arrival.
 *
 * These are suggestions only; a rider who wants more searches an external map.
 */
final class ResupplyBuilder
{
    /**
     * @param list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}> $food       food POIs, positioned along the route
     * @param list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}> $water      water points, positioned along the route
     * @param string                                                                                             $waterLabel localised fallback name for an unnamed water point
     */
    public function select(array $food, array $water, float $lunchKm, float $totalKm, string $waterLabel): Resupply
    {
        $morningWater = array_values(array_filter($water, static fn (array $w): bool => $w['distanceFromStart'] <= $lunchKm));
        $afternoonWater = array_values(array_filter($water, static fn (array $w): bool => $w['distanceFromStart'] >= $lunchKm));

        return new Resupply(
            foodAtLunch: array_map(fn (array $e): PointOfInterest => $this->toPoi($e, $waterLabel), $this->nearest($food, $lunchKm, 2)),
            waterMorning: $this->firstOrNull($this->nearest($morningWater, $lunchKm / 2, 1), $waterLabel),
            waterAfternoon: $this->firstOrNull($this->nearest($afternoonWater, ($lunchKm + $totalKm) / 2, 1), $waterLabel),
            foodAtArrival: array_map(fn (array $e): PointOfInterest => $this->toPoi($e, $waterLabel), $this->nearest($food, $totalKm, 2)),
        );
    }

    /**
     * The $count entries whose distanceFromStart is closest to $target.
     *
     * @param list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}> $entries
     *
     * @return list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}>
     */
    private function nearest(array $entries, float $target, int $count): array
    {
        usort(
            $entries,
            static fn (array $a, array $b): int => abs($a['distanceFromStart'] - $target) <=> abs($b['distanceFromStart'] - $target),
        );

        return \array_slice($entries, 0, $count);
    }

    /**
     * @param list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}> $entries
     */
    private function firstOrNull(array $entries, string $waterLabel): ?PointOfInterest
    {
        return [] === $entries ? null : $this->toPoi($entries[0], $waterLabel);
    }

    /**
     * @param array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float} $entry
     */
    private function toPoi(array $entry, string $waterLabel): PointOfInterest
    {
        return new PointOfInterest(
            name: $entry['name'] ?? $waterLabel,
            category: $entry['category'],
            lat: $entry['lat'],
            lon: $entry['lon'],
            distanceFromStart: $entry['distanceFromStart'],
        );
    }
}
