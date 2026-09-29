<?php

declare(strict_types=1);

namespace App\OpeningHours;

/**
 * One `HH:MM-HH:MM` span, kept as written: no range check, no midnight folding.
 * Each policy judges the numbers its own way.
 */
final readonly class TimeSpan
{
    /**
     * @param string $dashWhitespace the whitespace written around the dash, '' in `09:00-12:00`
     * @param string $separator      what sits before the span: the separator from the previous span, or the
     *                               whitespace after the selector for the first one ('' without selector)
     */
    public function __construct(
        public int $startHour,
        public int $startMinute,
        public int $endHour,
        public int $endMinute,
        public string $dashWhitespace,
        public string $separator,
    ) {
    }

    /** A line break inside the span or before it. */
    public function breaksLine(): bool
    {
        return str_contains($this->separator.$this->dashWhitespace, "\n");
    }

    /** Separated from the previous span by exactly one comma, whitespace aside. */
    public function followsSingleComma(): bool
    {
        return 1 === substr_count($this->separator, ',');
    }
}
