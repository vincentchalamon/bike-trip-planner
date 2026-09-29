<?php

declare(strict_types=1);

namespace App\Accommodation;

use App\OpeningHours\OpeningHoursGrammar;
use App\OpeningHours\SelectorKind;

/**
 * Determines seasonality of an OSM accommodation from its tags.
 *
 * Rules (in priority order):
 *  1. `opening_hours` present → parse simplified month-range patterns (e.g. "Apr-Oct", "May-Sep").
 *  2. `seasonal=yes` without `opening_hours` → closed November–March, open April–October.
 *  3. No relevant tags → null (undetermined).
 */
final readonly class SeasonalityChecker implements SeasonalityCheckerInterface
{
    /** Months considered "winter off-season" when seasonal=yes has no opening_hours. */
    private const array WINTER_MONTHS = [11, 12, 1, 2, 3];

    public function isLikelyOpen(\DateTimeImmutable $date, array $tags): ?bool
    {
        $openingHours = $tags['opening_hours'] ?? null;

        if (null !== $openingHours) {
            return $this->parseOpeningHours($openingHours, $date);
        }

        if ('yes' === ($tags['seasonal'] ?? null)) {
            $month = (int) $date->format('n');

            return !\in_array($month, self::WINTER_MONTHS, true);
        }

        return null;
    }

    /**
     * Reads a first rule made of a month range alone, optionally followed by time
     * spans ("Apr-Oct", "Oct-Mar", "Apr-Oct 10:00-20:00", "Jun-Sep; Mo off"), over
     * the shared {@see OpeningHoursGrammar}. Anything else is null: a season
     * followed by more than spans ("Apr-Oct 10:00-18:00, Nov-Mar 10:00-12:00")
     * says more than one season.
     */
    private function parseOpeningHours(string $openingHours, \DateTimeImmutable $date): ?bool
    {
        $rule = OpeningHoursGrammar::parse($openingHours)->rules[0] ?? null;

        if (null === $rule || null !== $rule->modifier || null === $rule->selector || 1 !== \count($rule->selector)) {
            return null;
        }

        $season = $rule->selector[0];

        if (SelectorKind::MONTH_RANGE !== $season->kind) {
            return null;
        }

        $month = (int) $date->format('n');

        if ($season->from <= $season->to) {
            // e.g. Apr(4)-Oct(10): contiguous range within a calendar year
            return $month >= $season->from && $month <= $season->to;
        }

        // e.g. Oct(10)-Mar(3): wraps across the year boundary
        return $month >= $season->from || $month <= $season->to;
    }
}
