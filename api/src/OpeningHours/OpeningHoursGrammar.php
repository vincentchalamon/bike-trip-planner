<?php

declare(strict_types=1);

namespace App\OpeningHours;

/**
 * The one reader of the OSM `opening_hours` grammar.
 *
 * It structures a value into {@see Rule}s (selector items, time spans, modifier)
 * and judges nothing: it never says open, closed or unknown, and never rejects.
 * Planning ({@see \App\Engine\OpeningHours}) and in-ride
 * ({@see \App\InRide\OpeningHoursParser}) each apply their own policy to the
 * same model; their verdicts differ on purpose (ADR-048 §4), their grammar
 * does not.
 *
 * Modelled: `24/7`; `;`-separated rules; a selector made of weekdays (`Mo`,
 * `Mo-Fr`, `Fr-Mo`, lists), `PH`, `SH`, or a single date (`dec 25`); time spans
 * (`09:00-12:00`, several separated by `,` or whitespace); a trailing
 * `off`/`closed`/`open`. Any other selector token becomes an UNKNOWN item, and a
 * rule whose time part does not read as spans keeps it inside its selector.
 */
final class OpeningHoursGrammar
{
    /** @var array<string, int> ISO-8601 weekday numbers (1 = Monday). */
    private const array WEEKDAYS = [
        'mo' => 1, 'tu' => 2, 'we' => 3, 'th' => 4,
        'fr' => 5, 'sa' => 6, 'su' => 7,
    ];

    /** @var array<string, int> */
    private const array MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private const string SPAN = '\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}';

    public static function parse(string $value): Schedule
    {
        $text = trim($value);
        $rules = [];

        foreach (explode(';', $text) as $rawRule) {
            $rule = trim($rawRule);

            if ('' !== $rule) {
                $rules[] = self::parseRule($rule);
            }
        }

        return new Schedule($text, $rules);
    }

    private static function parseRule(string $rule): Rule
    {
        if ('24/7' === $rule) {
            return new Rule(true, false, null, false, null, []);
        }

        if (1 === preg_match('/^(off|closed|open)$/i', $rule, $keyword)) {
            return new Rule(false, false, null, false, self::modifier($keyword[1]), []);
        }

        $holidayScoped = 1 === preg_match('/^(?:PH|SH)\b/i', $rule);
        $modifier = null;
        $body = $rule;

        // A trailing keyword needs whitespace before it: `Mooff` is one token.
        if (1 === preg_match('/^(.*?)\s+(off|closed)$/is', $rule, $matches) || 1 === preg_match('/^(.*?)\s+(open)$/is', $rule, $matches)) {
            $modifier = self::modifier($matches[2]);
            $body = trim($matches[1]);
        }

        // The time part is the trailing run of spans, separated from the selector by
        // whitespace. `??` tries "no selector" first, and the lazy selector keeps the
        // earliest split.
        $selectorText = $body;
        $gap = '';
        $spansText = '';

        if (1 === preg_match('/^(?:(.*?)(\s+))??('.self::SPAN.'(?:[\s,]+'.self::SPAN.')*)\s*$/s', $body, $matches)) {
            $selectorText = $matches[1];
            $gap = $matches[2];
            $spansText = $matches[3];
        }

        return new Rule(
            false,
            $holidayScoped,
            '' === $selectorText ? null : self::parseSelector($selectorText),
            str_contains($selectorText, "\n"),
            $modifier,
            self::parseSpans($gap, $spansText),
        );
    }

    private static function modifier(string $keyword): Modifier
    {
        return 'open' === strtolower($keyword) ? Modifier::OPEN : Modifier::OFF;
    }

    /**
     * @return list<SelectorItem>
     */
    private static function parseSelector(string $selector): array
    {
        if (1 === preg_match('/^([A-Za-z]{3})\s+(\d{1,2})$/', $selector, $date)) {
            $month = self::MONTHS[strtolower($date[1])] ?? null;

            return [null === $month ? SelectorItem::unknown() : SelectorItem::monthDay($month, (int) $date[2])];
        }

        return array_map(self::parseSelectorItem(...), explode(',', $selector));
    }

    private static function parseSelectorItem(string $rawItem): SelectorItem
    {
        $item = trim($rawItem);

        if ('' === $item) {
            return SelectorItem::empty();
        }

        if (1 === preg_match('/^(PH|SH)$/i', $item)) {
            $kind = 'PH' === strtoupper($item) ? SelectorKind::PUBLIC_HOLIDAY : SelectorKind::SCHOOL_HOLIDAY;

            return SelectorItem::holiday($kind, strtoupper($item) === $item);
        }

        if (1 === preg_match('/^([A-Za-z]{2})(\s*)-(\s*)([A-Za-z]{2})$/', $item, $range)) {
            $from = self::WEEKDAYS[strtolower($range[1])] ?? null;
            $to = self::WEEKDAYS[strtolower($range[4])] ?? null;

            if (null === $from || null === $to) {
                return SelectorItem::unknown();
            }

            $canonical = '' === $range[2] && '' === $range[3] && self::isCanonicalDay($range[1]) && self::isCanonicalDay($range[4]);

            return SelectorItem::weekdays($from, $to, $canonical);
        }

        $day = self::WEEKDAYS[strtolower($item)] ?? null;

        return null === $day ? SelectorItem::unknown() : SelectorItem::weekdays($day, $day, self::isCanonicalDay($item));
    }

    private static function isCanonicalDay(string $day): bool
    {
        return ucfirst(strtolower($day)) === $day;
    }

    /**
     * @param string $gap the whitespace between the selector and the first span
     *
     * @return list<TimeSpan>
     */
    private static function parseSpans(string $gap, string $spans): array
    {
        preg_match_all('/([\s,]*)(\d{1,2}):(\d{2})(\s*)-(\s*)(\d{1,2}):(\d{2})/', $spans, $matches, \PREG_SET_ORDER);

        return array_map(static fn (array $span): TimeSpan => new TimeSpan(
            (int) $span[2],
            (int) $span[3],
            (int) $span[6],
            (int) $span[7],
            $span[4].$span[5],
            '' === $span[1] ? $gap : $span[1],
        ), $matches);
    }
}
