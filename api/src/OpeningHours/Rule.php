<?php

declare(strict_types=1);

namespace App\OpeningHours;

/**
 * One `;`-separated rule: `[<selector> ]<time spans>[ <modifier>]`, or `24/7`.
 */
final readonly class Rule
{
    /**
     * @param bool                    $always             the rule is exactly `24/7`
     * @param bool                    $holidayScoped      the rule opens with `PH`/`SH`, in any case (`PH off`, `sh 10:00-12:00`)
     * @param list<SelectorItem>|null $selector           null when the rule has no selector, i.e. applies every day
     * @param bool                    $selectorBreaksLine the selector text holds a line break (`Mo,\nWe`)
     * @param Modifier|null           $modifier           a trailing `off`/`closed`/`open`, or the rule's only word
     * @param list<TimeSpan>          $spans              empty when the rule gives no time
     */
    public function __construct(
        public bool $always,
        public bool $holidayScoped,
        public ?array $selector,
        public bool $selectorBreaksLine,
        public ?Modifier $modifier,
        public array $spans,
    ) {
    }
}
