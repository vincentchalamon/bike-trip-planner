<?php

declare(strict_types=1);

namespace App\InRide;

use Yasumi\ProviderInterface;
use Yasumi\Yasumi;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Parses OSM `opening_hours` tags and answers "is it open?" questions.
 *
 * Scope: the most common patterns found on OSM in France/Belgium:
 *   - `24/7`
 *   - Weekday rules: `Mo`, `Tu`, ..., `Su` (single days, ranges `Mo-Fr`, lists `Mo,We,Fr`)
 *   - Time ranges: `09:00-12:00`, multiple per day separated by `,`
 *   - Multiple rule groups separated by `;`
 *   - `PH off` (public holiday closed) — best effort using `azuyalabs/yasumi`
 *   - `PH HH:MM-HH:MM` (public holiday hours)
 *   - Single-date holidays: `dec 25 off`
 *   - The `off` / `closed` modifier
 *
 * Out of scope (silently ignored — closed fallback): week numbers, month ranges,
 * sunset/sunrise, easter, complex date selectors. Unknown tokens cause the rule
 * to be skipped (defensive parsing — never throws).
 */
final class OpeningHoursParser
{
    private const array DAYS = ['Mo' => 1, 'Tu' => 2, 'We' => 3, 'Th' => 4, 'Fr' => 5, 'Sa' => 6, 'Su' => 7];

    private const array MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Yasumi provider locales evaluated by {@see self::isPublicHoliday()}. The
     * class docblock advertises France + Belgium coverage; both providers ship
     * with the `azuyalabs/yasumi` package the project already depends on.
     *
     * @var list<string>
     */
    private const array HOLIDAY_LOCALES = ['France', 'Belgium'];

    /**
     * Process-wide cache of Yasumi holiday providers keyed by `locale-year`.
     * Yasumi recomputes the full holiday set on every {@see Yasumi::create()}
     * call (~120 µs per call), so a typical in-ride page rendering N POIs with
     * `PH` tags would burn 2N × |locales| initialisations without this cache.
     *
     * @var array<string, ProviderInterface>
     */
    private static array $yasumiCache = [];

    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Returns true if the POI is open at `$now`, false otherwise (including when parsing fails).
     */
    public function isOpenNow(string $tag, \DateTimeImmutable $now): bool
    {
        $intervals = $this->intervalsForDate($tag, $now);
        if (null === $intervals) {
            return false;
        }

        foreach ($intervals as [$start, $end]) {
            if ($now >= $start && $now < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the closing time of the currently-open interval, or null if closed/unparseable.
     *
     * For intervals that span midnight (`22:00-02:00`), the returned datetime is on the next day.
     */
    public function closesAt(string $tag, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $intervals = $this->intervalsForDate($tag, $now);
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
     * Returns true if the POI is open continuously from `$now` until `$now + $duration`.
     */
    public function isOpenForAtLeast(string $tag, \DateTimeImmutable $now, \DateInterval $duration): bool
    {
        $closes = $this->closesAt($tag, $now);
        if (!$closes instanceof \DateTimeImmutable) {
            return false;
        }

        $deadline = $now->add($duration);

        return $closes >= $deadline;
    }

    /**
     * Computes the list of open intervals for the day containing `$now`, considering
     * intervals from the previous day that spill over past midnight.
     *
     * Returns null if the tag is empty or unparseable.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>|null
     */
    private function intervalsForDate(string $tag, \DateTimeImmutable $now): ?array
    {
        $tag = trim($tag);
        if ('' === $tag) {
            return null;
        }

        try {
            $today = $this->intervalsForSingleDate($tag, $now);
            $yesterday = $this->intervalsForSingleDate($tag, $now->modify('-1 day'));
        } catch (\Throwable $throwable) {
            $this->logger->info('Failed to parse opening_hours tag', [
                'tag' => $tag,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }

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
        $todayExplicitlyClosed = is_array($today) && [] === $today;
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
     * Parses the tag and returns intervals that apply to the given calendar date.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>|null
     */
    private function intervalsForSingleDate(string $tag, \DateTimeImmutable $date): ?array
    {
        $rules = array_map(trim(...), explode(';', $tag));
        $rules = array_values(array_filter($rules, static fn (string $r): bool => '' !== $r));

        if ([] === $rules) {
            return null;
        }

        /** @var list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $intervals */
        $intervals = [];
        $matchedAnyRule = false;
        $closedByRule = false;

        foreach ($rules as $rule) {
            $parsed = $this->parseRule($rule, $date);
            if (null === $parsed) {
                // Unparseable rule: skip but keep going — defensive.
                continue;
            }

            if (!$parsed['matches']) {
                continue;
            }

            $matchedAnyRule = true;

            if ($parsed['off']) {
                // Explicit off: this rule says closed on this date.
                $closedByRule = true;
                $intervals = [];
                continue;
            }

            // A positive rule cancels a previous "off" matched rule (later rules override).
            $closedByRule = false;
            foreach ($parsed['intervals'] as $interval) {
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
     * Parses a single rule (between `;` separators).
     *
     * @return array{matches: bool, off: bool, intervals: list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>}|null
     */
    private function parseRule(string $rule, \DateTimeImmutable $date): ?array
    {
        $rule = trim($rule);
        if ('' === $rule) {
            return null;
        }

        // 24/7 — always open.
        if ('24/7' === $rule) {
            return [
                'matches' => true,
                'off' => false,
                'intervals' => [[
                    $date->setTime(0, 0),
                    $date->modify('+1 day')->setTime(0, 0),
                ]],
            ];
        }

        // Detect trailing modifier (off | closed | open).
        $off = false;
        $body = $rule;
        if (preg_match('/^(.*?)\s+(off|closed)$/i', $rule, $m)) {
            $off = true;
            $body = trim($m[1]);
        } elseif (preg_match('/^(.*?)\s+open$/i', $rule, $m)) {
            $body = trim($m[1]);
        }

        // Split selector (date/day part) from time ranges.
        // Time ranges look like HH:MM-HH:MM; everything before the first one is the selector.
        $selector = $body;
        $timesPart = '';

        if (preg_match('/^(.*?)\s+(\d{1,2}:\d{2}-\d{1,2}:\d{2}(?:[\s,]+\d{1,2}:\d{2}-\d{1,2}:\d{2})*)\s*$/', $body, $m)) {
            $selector = trim($m[1]);
            $timesPart = trim($m[2]);
        } elseif (preg_match('/^(\d{1,2}:\d{2}-\d{1,2}:\d{2}(?:[\s,]+\d{1,2}:\d{2}-\d{1,2}:\d{2})*)$/', $body, $m)) {
            $selector = '';
            $timesPart = trim($m[1]);
        }

        $matches = $this->selectorMatches($selector, $date);
        if (null === $matches) {
            return null;
        }

        if (!$matches) {
            return ['matches' => false, 'off' => false, 'intervals' => []];
        }

        if ($off) {
            return ['matches' => true, 'off' => true, 'intervals' => []];
        }

        if ('' === $timesPart) {
            // Matched selector with no times and not "off" → treat as open all day.
            return [
                'matches' => true,
                'off' => false,
                'intervals' => [[
                    $date->setTime(0, 0),
                    $date->modify('+1 day')->setTime(0, 0),
                ]],
            ];
        }

        $intervals = $this->parseTimes($timesPart, $date);
        if (null === $intervals) {
            return null;
        }

        return ['matches' => true, 'off' => false, 'intervals' => $intervals];
    }

    /**
     * Returns true if the selector matches the given date, false if not, null if unparseable.
     * Empty selector means "every day".
     */
    private function selectorMatches(string $selector, \DateTimeImmutable $date): ?bool
    {
        $selector = trim($selector);
        if ('' === $selector) {
            return true;
        }

        // The selector may combine date-range and weekday parts. We support:
        //  - Weekday lists/ranges: `Mo-Fr`, `Sa`, `Mo,We,Fr`, `PH`
        //  - Single-date specifiers: `dec 25`, `Jan 1`
        // We try to detect a date specifier first; if found, it must include the date.
        // Otherwise we fall back to a weekday specifier.

        if (preg_match('/^([A-Za-z]{3})\s+(\d{1,2})$/', $selector, $m)) {
            $monthKey = strtolower($m[1]);
            if (!isset(self::MONTHS[$monthKey])) {
                return null;
            }

            $month = self::MONTHS[$monthKey];
            $day = (int) $m[2];

            return (int) $date->format('n') === $month && (int) $date->format('j') === $day;
        }

        // Weekday selector. May contain commas (lists) and dashes (ranges) and `PH`.
        return $this->weekdaySelectorMatches($selector, $date);
    }

    /**
     * Matches weekday-like selectors. Returns null for syntax we don't recognise.
     */
    private function weekdaySelectorMatches(string $selector, \DateTimeImmutable $date): ?bool
    {
        $parts = array_map(trim(...), explode(',', $selector));
        $dayOfWeek = (int) $date->format('N'); // 1=Mo .. 7=Su

        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }

            if ('PH' === $part) {
                if ($this->isPublicHoliday($date)) {
                    return true;
                }

                continue;
            }

            if (preg_match('/^([A-Z][a-z])-([A-Z][a-z])$/', $part, $m)) {
                if (!isset(self::DAYS[$m[1]], self::DAYS[$m[2]])) {
                    return null;
                }

                $from = self::DAYS[$m[1]];
                $to = self::DAYS[$m[2]];
                if ($from <= $to) {
                    if ($dayOfWeek >= $from && $dayOfWeek <= $to) {
                        return true;
                    }
                } elseif ($dayOfWeek >= $from || $dayOfWeek <= $to) {
                    // Wraparound: Fr-Mo means Fr, Sa, Su, Mo.
                    return true;
                }

                continue;
            }

            if (preg_match('/^[A-Z][a-z]$/', $part)) {
                if (!isset(self::DAYS[$part])) {
                    return null;
                }

                if (self::DAYS[$part] === $dayOfWeek) {
                    return true;
                }

                continue;
            }

            // Unknown selector token: bail out (defensive).
            return null;
        }

        return false;
    }

    /**
     * Best-effort public holiday detection using {@see self::HOLIDAY_LOCALES}.
     * `azuyalabs/yasumi` is a hard composer dependency of the project, so no
     * fallback is needed when the class is missing.
     */
    private function isPublicHoliday(\DateTimeImmutable $date): bool
    {
        try {
            $year = (int) $date->format('Y');
            $needle = new \DateTime($date->format('Y-m-d'), $date->getTimezone());

            // Check every supported locale (FR + BE) so a date that is a public
            // holiday in either country triggers `PH off` rules — a Belgian
            // shop tagged `Mo-Fr 09:00-18:00; PH off` must read as closed on
            // July 21 (Belgian National Day) even though Yasumi/France has no
            // such date.
            foreach (self::HOLIDAY_LOCALES as $locale) {
                $key = $locale.'-'.$year;
                if (!isset(self::$yasumiCache[$key])) {
                    self::$yasumiCache[$key] = Yasumi::create($locale, $year);
                }

                if (self::$yasumiCache[$key]->isHoliday($needle)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $throwable) {
            $this->logger->info('Failed to compute public holiday', ['error' => $throwable->getMessage()]);

            return false;
        }
    }

    /**
     * Parses a comma- or space-separated list of `HH:MM-HH:MM` ranges.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>|null
     */
    private function parseTimes(string $timesPart, \DateTimeImmutable $date): ?array
    {
        $ranges = preg_split('/[\s,]+/', $timesPart) ?: [];
        $ranges = array_values(array_filter($ranges, static fn (string $r): bool => '' !== $r));

        $intervals = [];
        foreach ($ranges as $range) {
            if (!preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $range, $m)) {
                return null;
            }

            $startH = (int) $m[1];
            $startM = (int) $m[2];
            $endH = (int) $m[3];
            $endM = (int) $m[4];

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

            if (24 === $endH && 0 === $endM) {
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
