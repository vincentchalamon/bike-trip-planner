<?php

declare(strict_types=1);

namespace App\Engine;

// DIP: no interface — single consumer, single implementation. Extract when a second consumer arises.
final readonly class PricingHeuristicEngine
{
    /** @var array<string, array{min: float, max: float}> */
    private const array PRICE_BRACKETS = [
        'camp_site' => ['min' => 8.0, 'max' => 25.0],
        'hostel' => ['min' => 20.0, 'max' => 35.0],
        'alpine_hut' => ['min' => 25.0, 'max' => 45.0],
        'chalet' => ['min' => 30.0, 'max' => 70.0],
        'guest_house' => ['min' => 40.0, 'max' => 80.0],
        'motel' => ['min' => 45.0, 'max' => 90.0],
        'hotel' => ['min' => 50.0, 'max' => 120.0],
        'wilderness_hut' => ['min' => 0.0, 'max' => 10.0],
        'shelter' => ['min' => 0.0, 'max' => 0.0],
    ];

    private const float BIKEPACKER_CAMP_SITE_MAX = 15.0;

    /** `fee` values meaning "this place charges nothing" (OSM `fee=no`). */
    private const array FREE_FEE_VALUES = ['no', 'false'];

    /**
     * Stars above this rating lift the bracket floor; 1 and 2 stars stay on it.
     * ADR-013 §13.2 only distinguished `stars > 2`.
     */
    private const int STAR_BASELINE = 2;

    /** Share of the bracket span each star above the baseline adds to the floor. */
    private const float STAR_FLOOR_LIFT_PER_STAR = 0.25;

    /** A 5-star entry keeps a bracket rather than collapsing onto its ceiling. */
    private const float MAX_STAR_FLOOR_LIFT = 0.75;

    /**
     * Returns estimated price range for an accommodation type.
     * If an exact charge tag or a numeric indexed `fee` is provided, returns it as
     * both min and max; `fee=no` prices the entry as free.
     * Recognises backpack=yes and tents=yes as bikepacker-friendly signals for camp_site.
     * A known star rating lifts the bracket floor (ADR-040 §45: heuristic is
     * type/region/**stars**), so a 4-star hotel is not budgeted like a 1-star one.
     *
     * @param array<string, string> $osmTags OSM tags for the accommodation element
     * @param ?int                  $stars   indexed star rating (osm.accommodations.stars)
     * @param ?string               $fee     indexed fee (osm.accommodations.fee, filled with `fee` or `charge`)
     *
     * @return array{min: float, max: float, isExact: bool}
     */
    public function estimatePrice(string $accommodationType, array $osmTags = [], ?int $stars = null, ?string $fee = null): array
    {
        // Exact price from the OSM `charge` tag (e.g. "15 EUR"), or from the
        // indexed `fee` column, which the provisioner fills with `fee` or `charge`.
        foreach ([$osmTags['charge'] ?? null, $fee] as $rawPrice) {
            $price = null !== $rawPrice ? $this->parseChargeTag($rawPrice) : null;
            if (null !== $price) {
                return ['min' => $price, 'max' => $price, 'isExact' => true];
            }
        }

        // `fee=no` is a statement, not a missing value: the entry is free.
        if (null !== $fee && \in_array(mb_strtolower(trim($fee)), self::FREE_FEE_VALUES, true)) {
            return ['min' => 0.0, 'max' => 0.0, 'isExact' => true];
        }

        $bracket = self::PRICE_BRACKETS[$accommodationType] ?? self::PRICE_BRACKETS['hotel'];
        $max = $bracket['max'];

        // Bikepacker-friendly camp sites (backpack=yes or tents=yes) tend to be cheaper
        if ('camp_site' === $accommodationType && ('yes' === ($osmTags['backpack'] ?? null) || 'yes' === ($osmTags['tents'] ?? null))) {
            $max = self::BIKEPACKER_CAMP_SITE_MAX;
        }

        return ['min' => $this->applyStars($bracket['min'], $max, $stars), 'max' => $max, 'isExact' => false];
    }

    /**
     * Raises the bracket floor within the bracket span, proportionally to the
     * stars above the baseline; the ceiling is unchanged.
     */
    private function applyStars(float $min, float $max, ?int $stars): float
    {
        if (null === $stars || $stars <= self::STAR_BASELINE) {
            return $min;
        }

        $lift = min(self::MAX_STAR_FLOOR_LIFT, ($stars - self::STAR_BASELINE) * self::STAR_FLOOR_LIFT_PER_STAR);

        return round($min + ($max - $min) * $lift, 2);
    }

    private function parseChargeTag(string $charge): ?float
    {
        // Extract numeric value from strings like "15 EUR", "15€", "15.50"
        if (preg_match('/(\d+(?:[.,]\d+)?)/', $charge, $matches)) {
            return (float) str_replace(',', '.', $matches[1]);
        }

        return null;
    }
}
