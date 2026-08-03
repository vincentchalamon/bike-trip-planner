<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * A deliberately small reader of the OSM `opening_hours` grammar.
 *
 * Only the shapes that cover the vast majority of resupply POIs are modelled:
 * `24/7`, a bare list of time spans (`09:00-12:00,14:00-19:00`), and
 * `;`-separated rules made of an optional weekday selector (`Mo`, `Mo-Fr`,
 * `Mo,We,Fr`, `Mo-Fr,Su`) followed by either `off`/`closed` or time spans.
 *
 * Anything else — month ranges, `week`, `sunrise`, `Su[1]`, `open`, comments —
 * makes {@see parse} return null. The value is then *unknown*, never *closed*:
 * callers must not conclude on a string they did not understand.
 *
 * `PH`/`SH` (public/school holiday) rules are skipped rather than rejected.
 * Whether a date is a public holiday is the calendar checker's business, and
 * ignoring such an exception can only make a POI look open — the safe direction
 * for a warning that fires when everything is closed.
 */
final readonly class OpeningHours
{
    /** @var array<string, int> ISO-8601 weekday numbers (1 = Monday). */
    private const array WEEKDAYS = [
        'mo' => 1, 'tu' => 2, 'we' => 3, 'th' => 4,
        'fr' => 5, 'sa' => 6, 'su' => 7,
    ];

    /**
     * @param array<int, list<array{open: float, close: float}>> $slotsByWeekday ISO weekday => open slots, in decimal hours
     */
    private function __construct(
        private array $slotsByWeekday,
    ) {
    }

    /**
     * Returns null when the string is not one of the modelled shapes.
     */
    public static function parse(string $spec): ?self
    {
        $spec = trim($spec);

        if ('24/7' === $spec) {
            return new self(array_fill_keys(range(1, 7), [['open' => 0.0, 'close' => 24.0]]));
        }

        $slotsByWeekday = [];
        $matched = false;

        foreach (explode(';', $spec) as $rawRule) {
            $rule = trim($rawRule);

            if ('' === $rule) {
                continue;
            }

            if (1 === preg_match('/^(?:PH|SH)\b/i', $rule)) {
                continue;
            }

            $parsed = self::parseRule($rule);

            if (null === $parsed) {
                return null;
            }

            [$days, $slots] = $parsed;

            foreach ($days as $day) {
                $slotsByWeekday[$day] = $slots;
            }

            $matched = true;
        }

        if (!$matched) {
            return null;
        }

        // Weekdays no rule mentions are closed — standard opening_hours semantics.
        foreach (range(1, 7) as $day) {
            $slotsByWeekday[$day] ??= [];
        }

        return new self($slotsByWeekday);
    }

    /**
     * Tri-state openness: true = open, false = closed, null = the answer depends
     * on the weekday and the weekday is unknown, so nothing can be concluded.
     *
     * @param float    $decimalHour e.g. 13.5 for 13:30
     * @param int|null $isoWeekday  1 (Monday) to 7 (Sunday), null when the date is unknown
     */
    public function isOpenAt(float $decimalHour, ?int $isoWeekday = null): ?bool
    {
        if (null !== $isoWeekday) {
            return $this->isWithin($this->slotsByWeekday[$isoWeekday] ?? [], $decimalHour);
        }

        $open = $this->isWithin($this->slotsByWeekday[1], $decimalHour);

        foreach ($this->slotsByWeekday as $slots) {
            if ($this->isWithin($slots, $decimalHour) !== $open) {
                return null;
            }
        }

        return $open;
    }

    /**
     * A single `;`-separated rule: `[<weekday selector> ]<time spans|off>`.
     *
     * @return array{list<int>, list<array{open: float, close: float}>}|null
     */
    private static function parseRule(string $rule): ?array
    {
        $days = range(1, 7);
        $rest = $rule;

        if (1 === preg_match('/^((?:Mo|Tu|We|Th|Fr|Sa|Su)(?:\s*[-,]\s*(?:Mo|Tu|We|Th|Fr|Sa|Su))*)\s+(.*)$/i', $rule, $matches)) {
            $selected = self::parseWeekdays($matches[1]);

            if (null === $selected) {
                return null;
            }

            $days = $selected;
            $rest = trim($matches[2]);
        }

        if (\in_array(strtolower($rest), ['off', 'closed'], true)) {
            return [$days, []];
        }

        $slots = [];

        foreach (explode(',', $rest) as $rawSpan) {
            if (1 !== preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $rawSpan, $span)) {
                return null;
            }

            $open = (int) $span[1] + (int) $span[2] / 60;
            $close = (int) $span[3] + (int) $span[4] / 60;

            if ($open > 24.0 || $close > 24.0) {
                return null;
            }

            if ($close < $open) {
                // Crossing midnight: the tail really belongs to the next day, but
                // folding it back into the same day only widens the open window,
                // which is the safe direction here.
                $slots[] = ['open' => $open, 'close' => 24.0];
                $slots[] = ['open' => 0.0, 'close' => $close];

                continue;
            }

            $slots[] = ['open' => $open, 'close' => $close];
        }

        return [$days, $slots];
    }

    /**
     * @return list<int>|null
     */
    private static function parseWeekdays(string $selector): ?array
    {
        $days = [];

        foreach (explode(',', $selector) as $part) {
            $part = trim($part);

            if (1 === preg_match('/^([A-Za-z]{2})\s*-\s*([A-Za-z]{2})$/', $part, $range)) {
                $from = self::WEEKDAYS[strtolower($range[1])] ?? null;
                $to = self::WEEKDAYS[strtolower($range[2])] ?? null;

                if (null === $from || null === $to) {
                    return null;
                }

                for ($day = $from;; $day = $day % 7 + 1) {
                    $days[] = $day;

                    if ($day === $to) {
                        break;
                    }
                }

                continue;
            }

            $day = self::WEEKDAYS[strtolower($part)] ?? null;

            if (null === $day) {
                return null;
            }

            $days[] = $day;
        }

        return array_values(array_unique($days));
    }

    /**
     * @param list<array{open: float, close: float}> $slots
     */
    private function isWithin(array $slots, float $decimalHour): bool
    {
        return array_any($slots, static fn (array $slot): bool => $decimalHour >= $slot['open'] && $decimalHour <= $slot['close']);
    }
}
