<?php

declare(strict_types=1);

namespace App\OpeningHours;

/**
 * An `opening_hours` value read by {@see OpeningHoursGrammar}: every non-empty
 * rule in order, each one structured, none judged. Whether a rule means open,
 * closed or unknown, or is acceptable at all, is the reading policy's call.
 */
final readonly class Schedule
{
    /**
     * @param string     $text  the whole value, trimmed
     * @param list<Rule> $rules
     */
    public function __construct(
        public string $text,
        public array $rules,
    ) {
    }
}
