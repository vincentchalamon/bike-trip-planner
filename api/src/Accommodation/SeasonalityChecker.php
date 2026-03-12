<?php

declare(strict_types=1);

namespace App\Accommodation;

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
    /** @var array<string, int> */
    private const array MONTH_MAP = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

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
     * Parses simplified opening_hours strings of the form "Mmm-Mmm" (optionally followed by time ranges).
     *
     * Examples handled: "Apr-Oct", "May-Sep", "Apr-Oct 10:00-20:00", "Jun-Sep; Mo off".
     * Returns null when the pattern is not recognised.
     */
    private function parseOpeningHours(string $openingHours, \DateTimeImmutable $date): ?bool
    {
        // Normalise: strip time/day-of-week suffixes and take only the first rule
        $rule = trim(explode(';', $openingHours)[0]);
        $rule = trim(preg_replace('/\s+\d{2}:\d{2}-\d{2}:\d{2}.*/', '', $rule) ?? $rule);

        if (!preg_match('/^([A-Za-z]{3})-([A-Za-z]{3})$/', $rule, $matches)) {
            return null;
        }

        $fromKey = strtolower($matches[1]);
        $toKey = strtolower($matches[2]);

        if (!isset(self::MONTH_MAP[$fromKey], self::MONTH_MAP[$toKey])) {
            return null;
        }

        $from = self::MONTH_MAP[$fromKey];
        $to = self::MONTH_MAP[$toKey];
        $month = (int) $date->format('n');

        if ($from <= $to) {
            // e.g. Apr(4)-Oct(10): contiguous range within a calendar year
            return $month >= $from && $month <= $to;
        }

        // e.g. Oct(10)-Mar(3): wraps across the year boundary
        return $month >= $from || $month <= $to;
    }
}
