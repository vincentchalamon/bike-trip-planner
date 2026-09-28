<?php

declare(strict_types=1);

namespace App\Engine;

use App\OpeningHours\Modifier;
use App\OpeningHours\OpeningHoursGrammar;
use App\OpeningHours\Rule;
use App\OpeningHours\SelectorKind;

/**
 * Planning's reading of an OSM `opening_hours` value, over the shared
 * {@see OpeningHoursGrammar}. Deliberately narrow.
 *
 * Only the shapes that cover the vast majority of resupply POIs are accepted:
 * `24/7` alone, a bare list of time spans (`09:00-12:00,14:00-19:00`), and
 * `;`-separated rules made of an optional weekday selector (`Mo`, `Mo-Fr`,
 * `Mo,We,Fr`, `Mo-Fr,Su`, in any case) followed by either `off`/`closed` or
 * comma-separated time spans.
 *
 * Anything else — month ranges, dates, `week`, `sunrise`, `Su[1]`, `open`,
 * comments — makes {@see parse} return null. The value is then *unknown*, never
 * *closed*: callers must not conclude on a string they did not understand.
 *
 * `PH`/`SH` (public/school holiday) rules are skipped rather than rejected.
 * Whether a date is a public holiday is the calendar checker's business, and
 * ignoring such an exception can only make a POI look open — the safe direction
 * for a warning that fires when everything is closed.
 */
final readonly class OpeningHours
{
    /**
     * @param array<int, list<array{open: float, close: float}>> $slotsByWeekday ISO weekday => open slots, in decimal hours
     */
    private function __construct(
        private array $slotsByWeekday,
    ) {
    }

    /**
     * Returns null when the string is not one of the accepted shapes.
     */
    public static function parse(string $spec): ?self
    {
        $schedule = OpeningHoursGrammar::parse($spec);

        if ('24/7' === $schedule->text) {
            return new self(array_fill_keys(range(1, 7), [['open' => 0.0, 'close' => 24.0]]));
        }

        $slotsByWeekday = [];
        $matched = false;

        foreach ($schedule->rules as $rule) {
            if ($rule->holidayScoped) {
                continue;
            }

            $judged = self::judge($rule);

            if (null === $judged) {
                return null;
            }

            [$days, $slots] = $judged;

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
     * The days a rule covers and their slots, or null when planning does not
     * accept the rule: `24/7` among other rules, `open`, a selector that is not
     * only weekdays, `off` next to times, spans not separated by one comma, a
     * time part broken across lines after a selector.
     *
     * @return array{list<int>, list<array{open: float, close: float}>}|null
     */
    private static function judge(Rule $rule): ?array
    {
        if ($rule->always || Modifier::OPEN === $rule->modifier) {
            return null;
        }

        $days = range(1, 7);

        if (null !== $rule->selector) {
            $days = [];

            foreach ($rule->selector as $item) {
                if (SelectorKind::WEEKDAYS !== $item->kind) {
                    return null;
                }

                array_push($days, ...$item->weekdayList());
            }

            $days = array_values(array_unique($days));
        }

        if (Modifier::OFF === $rule->modifier) {
            return [] === $rule->spans ? [$days, []] : null;
        }

        if ([] === $rule->spans) {
            return null;
        }

        $slots = [];

        foreach ($rule->spans as $index => $span) {
            if (0 !== $index && !$span->followsSingleComma()) {
                return null;
            }

            // After a selector, the time part must sit on one line (the whitespace
            // right after the selector aside); a bare time part may wrap.
            if (null !== $rule->selector && (str_contains($span->dashWhitespace, "\n") || (0 !== $index && str_contains($span->separator, "\n")))) {
                return null;
            }

            $open = $span->startHour + $span->startMinute / 60;
            $close = $span->endHour + $span->endMinute / 60;

            if ($open > 24.0 || $close > 24.0) {
                return null;
            }

            if ($close < $open) {
                if (7 !== \count($days)) {
                    // The tail belongs to the *next* day, which this reader does not
                    // track per day. Folding it back would leave that next day with no
                    // rule at all, hence reported as known closed during the spillover —
                    // a closure nothing established. Unknown is the honest answer.
                    return null;
                }

                // Crossing midnight, but every day carries the same rule, so folding the
                // tail into the same day cannot misattribute it: the next day owns an
                // identical slot anyway. This only widens the open window.
                $slots[] = ['open' => $open, 'close' => 24.0];
                $slots[] = ['open' => 0.0, 'close' => $close];

                continue;
            }

            $slots[] = ['open' => $open, 'close' => $close];
        }

        return [$days, $slots];
    }

    /**
     * @param list<array{open: float, close: float}> $slots
     */
    private function isWithin(array $slots, float $decimalHour): bool
    {
        return array_any($slots, static fn (array $slot): bool => $decimalHour >= $slot['open'] && $decimalHour <= $slot['close']);
    }
}
