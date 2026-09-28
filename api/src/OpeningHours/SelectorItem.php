<?php

declare(strict_types=1);

namespace App\OpeningHours;

/**
 * One comma-separated item of a rule's selector.
 *
 * `canonical` records whether the item is written in OSM's canonical spelling
 * (`Mo-Fr`, `PH`) rather than a tolerated variant (`mo-fr`, `Mo - Fr`, `ph`):
 * planning reads both, in-ride only the canonical one.
 */
final readonly class SelectorItem
{
    /**
     * @param int $from first weekday (1 = Monday) for WEEKDAYS, month for MONTH_DAY
     * @param int $to   last weekday for WEEKDAYS, day of the month for MONTH_DAY
     */
    private function __construct(
        public SelectorKind $kind,
        public bool $canonical = true,
        public int $from = 0,
        public int $to = 0,
    ) {
    }

    public static function weekdays(int $from, int $to, bool $canonical): self
    {
        return new self(SelectorKind::WEEKDAYS, $canonical, $from, $to);
    }

    public static function holiday(SelectorKind $kind, bool $canonical): self
    {
        return new self($kind, $canonical);
    }

    public static function monthDay(int $month, int $day): self
    {
        return new self(SelectorKind::MONTH_DAY, true, $month, $day);
    }

    public static function empty(): self
    {
        return new self(SelectorKind::EMPTY);
    }

    public static function unknown(): self
    {
        return new self(SelectorKind::UNKNOWN, false);
    }

    /**
     * The ISO weekdays of a WEEKDAYS item, `Fr-Mo` wrapping past Sunday.
     *
     * @return list<int>
     */
    public function weekdayList(): array
    {
        $days = [];

        for ($day = $this->from;; $day = $day % 7 + 1) {
            $days[] = $day;

            if ($day === $this->to) {
                return $days;
            }
        }
    }
}
