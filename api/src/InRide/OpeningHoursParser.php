<?php

declare(strict_types=1);

namespace App\InRide;

use App\OpeningHours\Modifier;
use App\OpeningHours\OpeningHoursGrammar;
use App\OpeningHours\PublicHolidayCalendar;
use App\OpeningHours\Rule;
use App\OpeningHours\SelectorItem;
use App\OpeningHours\SelectorKind;
use App\OpeningHours\TimeSpan;

/**
 * In-ride's reading of an OSM `opening_hours` value, over the shared
 * {@see OpeningHoursGrammar}: answers "is it open now?" for a given date-time.
 *
 * Understood: `24/7`; weekday rules (`Mo`, ranges `Mo-Fr`, lists `Mo,We,Fr`, in
 * their canonical spelling); several time spans per rule; `;`-separated rules,
 * later positive rules adding to earlier ones; `PH` (public holidays of
 * {@see self::DEFAULT_HOLIDAY_COUNTRIES} unless told otherwise); single-date
 * rules (`dec 25 off`); the `off`/`closed`/`open` modifiers; spans crossing
 * midnight.
 *
 * A rule it cannot read (week numbers, month ranges, sunrise/sunset, `SH`...) is
 * skipped, never fatal: the other rules still decide.
 */
final readonly class OpeningHoursParser
{
    /**
     * Countries whose public holidays a `PH` rule refers to when the caller does
     * not name them: in-ride coverage is France and Belgium.
     *
     * @var list<string>
     */
    public const array DEFAULT_HOLIDAY_COUNTRIES = ['FR', 'BE'];

    public function __construct(
        private PublicHolidayCalendar $holidays = new PublicHolidayCalendar(),
    ) {
    }

    /**
     * Tri-state opening verdict at `$now`, derived caller-side from the private
     * {@see self::intervalsForDate()} (never mutating it — its `null` vs `[]`
     * distinction guards the night-overflow bleed).
     *
     * - intervals present and `$now` inside one  -> OPEN
     * - intervals present and `$now` outside them -> CLOSED
     * - no intervals (no rule applies for the date OR tag unreadable):
     *     - at least one rule of the tag is parseable -> CLOSED, OSM omission
     *       semantics: `Mo-Fr 09:00-17:00` on a Sunday is genuinely closed, the
     *       tag simply omits the day;
     *     - otherwise -> UNKNOWN, the tag says nothing (`garbage data here`,
     *       empty), so the POI stays visible with a warning.
     *
     * Deliberate divergence from {@see \App\Engine\OpeningHours::isOpenAt()}:
     * in-ride discards a line that is almost certainly closed, while planning
     * keeps it with an uncertainty flag. Both agree that "no information" is
     * never "closed".
     *
     * @param list<string> $holidayCountries ISO 3166-1 alpha-2 codes a `PH` rule refers to
     */
    public function status(string $tag, \DateTimeImmutable $now, array $holidayCountries = self::DEFAULT_HOLIDAY_COUNTRIES): OpeningStatus
    {
        $rules = OpeningHoursGrammar::parse($tag)->rules;

        $intervals = $this->intervalsForDate($rules, $now, $holidayCountries);
        if (null !== $intervals) {
            foreach ($intervals as [$start, $end]) {
                if ($now >= $start && $now < $end) {
                    return OpeningStatus::OPEN;
                }
            }

            return OpeningStatus::CLOSED;
        }

        return $this->hasAnyParseableRule($rules, $now, $holidayCountries) ? OpeningStatus::CLOSED : OpeningStatus::UNKNOWN;
    }

    /**
     * Returns the closing time of the currently-open interval, or null if closed/unparseable.
     *
     * For intervals that span midnight (`22:00-02:00`), the returned datetime is on the next day.
     *
     * @param list<string> $holidayCountries ISO 3166-1 alpha-2 codes a `PH` rule refers to
     */
    public function closesAt(string $tag, \DateTimeImmutable $now, array $holidayCountries = self::DEFAULT_HOLIDAY_COUNTRIES): ?\DateTimeImmutable
    {
        $intervals = $this->intervalsForDate(OpeningHoursGrammar::parse($tag)->rules, $now, $holidayCountries);
        if (null === $intervals) {
            return null;
        }

        foreach ($intervals as [$start, $end]) {
            if ($now >= $start && $now < $end) {
                return $end;
            }
        }

        return null;
    }

    /**
     * Returns true if at least one rule of the tag is readable, regardless of
     * whether it applies to `$date`. Distinguishes a tag that is merely silent
     * for the date (parseable -> closed) from one that is noise
     * (`garbage data here`, empty -> unknown).
     *
     * @param list<Rule>   $rules
     * @param list<string> $holidayCountries
     */
    private function hasAnyParseableRule(array $rules, \DateTimeImmutable $date, array $holidayCountries): bool
    {
        return array_any($rules, fn (Rule $rule): bool => null !== $this->judge($rule, $date, $holidayCountries));
    }

    /**
     * Computes the list of open intervals for the day containing `$now`, considering
     * intervals from the previous day that spill over past midnight.
     *
     * Returns null when neither day has a rule that applies.
     *
     * @param list<Rule>   $rules
     * @param list<string> $holidayCountries
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>|null
     */
    private function intervalsForDate(array $rules, \DateTimeImmutable $now, array $holidayCountries): ?array
    {
        $today = $this->intervalsForSingleDate($rules, $now, $holidayCountries);
        $yesterday = $this->intervalsForSingleDate($rules, $now->modify('-1 day'), $holidayCountries);

        // Neither date had a rule for the tag → no information available.
        if (null === $today && null === $yesterday) {
            return null;
        }

        $intervals = [];

        // Include overnight intervals from yesterday that bleed into today —
        // but only when today is not explicitly closed by a rule. A tag like
        // `22:00-02:00; PH off` must stay closed all day on a public holiday,
        // even though yesterday's 22:00-02:00 interval otherwise crosses
        // midnight. `intervalsForSingleDate` returns `null` for "no rule
        // matched" and `[]` for "explicitly closed".
        $todayExplicitlyClosed = [] === $today;
        if (!$todayExplicitlyClosed) {
            foreach ($yesterday ?? [] as [$start, $end]) {
                if ($end > $start && $end->format('Y-m-d') !== $start->format('Y-m-d')) {
                    $intervals[] = [$start, $end];
                }
            }
        }

        foreach ($today ?? [] as $interval) {
            $intervals[] = $interval;
        }

        return $intervals;
    }

    /**
     * The intervals the rules give the calendar date: null when no rule applies
     * to it, `[]` when one closes it.
     *
     * @param list<Rule>   $rules
     * @param list<string> $holidayCountries
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>|null
     */
    private function intervalsForSingleDate(array $rules, \DateTimeImmutable $date, array $holidayCountries): ?array
    {
        /** @var list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $intervals */
        $intervals = [];
        $matchedAnyRule = false;
        $closedByRule = false;

        foreach ($rules as $rule) {
            $judged = $this->judge($rule, $date, $holidayCountries);
            if (null === $judged) {
                // Unreadable rule: skip but keep going — defensive.
                continue;
            }

            if (!$judged['matches']) {
                continue;
            }

            $matchedAnyRule = true;

            if ($judged['off']) {
                // Explicit off: this rule says closed on this date.
                $closedByRule = true;
                $intervals = [];
                continue;
            }

            // A positive rule cancels a previous "off" matched rule (later rules override).
            $closedByRule = false;
            foreach ($judged['intervals'] as $interval) {
                $intervals[] = $interval;
            }
        }

        if (!$matchedAnyRule) {
            // No rule matched this calendar date — the tag is simply silent
            // about it. Return null so the caller can distinguish this "no
            // information" case from "explicitly closed" (returning []).
            return null;
        }

        if ($closedByRule) {
            return [];
        }

        return $intervals;
    }

    /**
     * What one rule says about `$date`, or null when in-ride cannot read it.
     *
     * @param list<string> $holidayCountries
     *
     * @return array{matches: bool, off: bool, intervals: list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>}|null
     */
    private function judge(Rule $rule, \DateTimeImmutable $date, array $holidayCountries): ?array
    {
        $allDay = [[$date->setTime(0, 0), $date->modify('+1 day')->setTime(0, 0)]];

        if ($rule->always) {
            return ['matches' => true, 'off' => false, 'intervals' => $allDay];
        }

        $read = $this->read($rule);
        if (null === $read) {
            return null;
        }

        [$selector, $spans, $modifier] = $read;

        $matches = $this->selectorMatches($selector, $date, $holidayCountries);
        if (null === $matches) {
            return null;
        }

        if (!$matches) {
            return ['matches' => false, 'off' => false, 'intervals' => []];
        }

        if (Modifier::OFF === $modifier) {
            return ['matches' => true, 'off' => true, 'intervals' => []];
        }

        if ([] === $spans) {
            // Matched selector with no times and not "off" → treat as open all day.
            return ['matches' => true, 'off' => false, 'intervals' => $allDay];
        }

        $intervals = $this->intervals($spans, $date);
        if (null === $intervals) {
            return null;
        }

        return ['matches' => true, 'off' => false, 'intervals' => $intervals];
    }

    /**
     * The selector, spans and modifier in-ride reads from a rule, or null when it
     * reads nothing.
     *
     * This is the tokenisation in-ride has always had, pinned by the
     * characterisation matrix. It only reads compact spans (`09:00-12:00`, no
     * whitespace around the dash), it never looks for a keyword or for spans
     * across a line break, and text it cannot read as spans or keyword stays in the
     * selector: the selector item it lands in turns unreadable, so only the items
     * before it can still match. A rule without selector whose spans follow
     * whitespace (`09:00-12:00, 14:00-18:00`) thus reads its first spans as a
     * selector, hence nothing.
     *
     * @return array{list<SelectorItem>|null, list<TimeSpan>, Modifier|null}|null
     */
    private function read(Rule $rule): ?array
    {
        $selector = $rule->selector;
        $spans = $rule->spans;
        $modifier = $rule->modifier;

        if (null === $selector && $modifier instanceof Modifier && [] === $spans) {
            // A bare `off`/`open`: no whitespace precedes the keyword, so it is the selector.
            return null;
        }

        $breaksLine = $rule->selectorBreaksLine || array_any($spans, static fn (TimeSpan $span): bool => $span->breaksLine());
        if ($modifier instanceof Modifier && $breaksLine) {
            return [$this->lastItemUnreadable($selector), [], null];
        }

        if ([] !== $spans && $rule->selectorBreaksLine) {
            return [$this->lastItemUnreadable($selector), [], $modifier];
        }

        $compact = array_all($spans, static fn (TimeSpan $span): bool => $span->isCompact());

        if (null === $selector) {
            return $compact && null === $this->splitIndex($spans, false) ? [null, $spans, $modifier] : null;
        }

        if ($compact) {
            return [$selector, $spans, $modifier];
        }

        $split = $this->splitIndex($spans, true);

        return [$this->lastItemUnreadable($selector), null === $split ? [] : \array_slice($spans, $split), $modifier];
    }

    /**
     * The first span after which in-ride would cut the rule: it follows
     * whitespace, no line break precedes that whitespace, and, when asked, it
     * starts a compact tail.
     *
     * @param list<TimeSpan> $spans
     */
    private function splitIndex(array $spans, bool $compactTail): ?int
    {
        $lineBroken = false;

        foreach ($spans as $index => $span) {
            if (0 !== $index
                && !$lineBroken
                && $span->followsWhitespace()
                && !$span->breaksLineBeforeTrailingWhitespace()
                && (!$compactTail || array_all(\array_slice($spans, $index), static fn (TimeSpan $tail): bool => $tail->isCompact()))
            ) {
                return $index;
            }

            $lineBroken = $lineBroken || $span->breaksLine();
        }

        return null;
    }

    /**
     * @param list<SelectorItem>|null $selector null reads as a single item
     *
     * @return list<SelectorItem>
     */
    private function lastItemUnreadable(?array $selector): array
    {
        $selector ??= [];
        array_pop($selector);
        $selector[] = SelectorItem::unknown();

        return $selector;
    }

    /**
     * Returns true if the selector matches the given date, false if not, null if
     * unreadable. No selector means "every day". Items are tried in order: the
     * first that matches wins, the first unreadable one before any match makes the
     * whole selector unreadable.
     *
     * @param list<SelectorItem>|null $selector
     * @param list<string>            $holidayCountries
     */
    private function selectorMatches(?array $selector, \DateTimeImmutable $date, array $holidayCountries): ?bool
    {
        if (null === $selector) {
            return true;
        }

        $dayOfWeek = (int) $date->format('N'); // 1=Mo .. 7=Su

        foreach ($selector as $item) {
            if (SelectorKind::EMPTY === $item->kind) {
                continue;
            }

            if (!$item->canonical) {
                return null;
            }

            switch ($item->kind) {
                case SelectorKind::MONTH_DAY:
                    return (int) $date->format('n') === $item->from && (int) $date->format('j') === $item->to;
                case SelectorKind::PUBLIC_HOLIDAY:
                    if ($this->holidays->isHoliday($date, $holidayCountries)) {
                        return true;
                    }

                    break;
                case SelectorKind::WEEKDAYS:
                    if (\in_array($dayOfWeek, $item->weekdayList(), true)) {
                        return true;
                    }

                    break;
                default:
                    return null;
            }
        }

        return false;
    }

    /**
     * Turns spans into intervals on `$date`, or null when a span is out of range.
     *
     * @param list<TimeSpan> $spans
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>|null
     */
    private function intervals(array $spans, \DateTimeImmutable $date): ?array
    {
        $intervals = [];
        foreach ($spans as $span) {
            $startH = $span->startHour;
            $startM = $span->startMinute;
            $endH = $span->endHour;
            $endM = $span->endMinute;

            // OSM accepts 24:00 only as an *end* marker (midnight of the next day),
            // so the start hour caps at 23 while the end hour caps at 24 with a
            // zero minute (`24:30` would not be valid OSM and PHP would silently
            // normalise it to `00:30 next day`, producing a wrong open interval).
            if ($startH > 23 || $endH > 24 || $startM > 59 || $endM > 59) {
                return null;
            }

            if (24 === $endH && 0 !== $endM) {
                return null;
            }

            $start = $date->setTime($startH, $startM);

            if (24 === $endH) {
                $end = $date->modify('+1 day')->setTime(0, 0);
            } elseif ($endH < $startH) {
                // Overnight: end is on the next day.
                $end = $date->modify('+1 day')->setTime($endH, $endM);
            } else {
                $end = $date->setTime($endH, $endM);
            }

            if ($end <= $start) {
                // Defensive: skip nonsense range.
                continue;
            }

            $intervals[] = [$start, $end];
        }

        return $intervals;
    }
}
