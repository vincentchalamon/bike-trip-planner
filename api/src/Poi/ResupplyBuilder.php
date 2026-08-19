<?php

declare(strict_types=1);

namespace App\Poi;

use App\ApiResource\Model\PointOfInterest;

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
 * At most 6, deduplicated by coordinate — a short stage where lunch coincides
 * with the arrival yields fewer. These are suggestions only; a rider who wants
 * more searches an external map.
 */
final class ResupplyBuilder
{
    /**
     * @param list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}> $food       food POIs, positioned along the route
     * @param list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}> $water      water points, positioned along the route
     * @param string                                                                                             $waterLabel localised fallback name for an unnamed water point
     *
     * @return list<PointOfInterest>
     */
    public function select(array $food, array $water, float $lunchKm, float $totalKm, string $waterLabel): array
    {
        $morningWater = array_values(array_filter($water, static fn (array $w): bool => $w['distanceFromStart'] <= $lunchKm));
        $afternoonWater = array_values(array_filter($water, static fn (array $w): bool => $w['distanceFromStart'] >= $lunchKm));

        $picked = [
            ...$this->nearest($food, $lunchKm, 2),
            ...$this->nearest($morningWater, $lunchKm / 2, 1),
            ...$this->nearest($afternoonWater, ($lunchKm + $totalKm) / 2, 1),
            ...$this->nearest($food, $totalKm, 2),
        ];

        $pois = [];
        $seen = [];
        foreach ($picked as $entry) {
            $key = $entry['lat'].','.$entry['lon'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $pois[] = new PointOfInterest(
                name: $entry['name'] ?? $waterLabel,
                category: $entry['category'],
                lat: $entry['lat'],
                lon: $entry['lon'],
                distanceFromStart: $entry['distanceFromStart'],
            );
        }

        return $pois;
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
}
