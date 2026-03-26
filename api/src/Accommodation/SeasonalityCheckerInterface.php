<?php

declare(strict_types=1);

namespace App\Accommodation;

use DateTimeImmutable;

/**
 * Checks whether an accommodation is likely open on a given date,
 * based on OSM tags such as `seasonal` and `opening_hours`.
 */
interface SeasonalityCheckerInterface
{
    /**
     * Returns true if the accommodation is likely open on $date,
     * false if it is likely closed, or null if it cannot be determined.
     *
     * @param array<string, string> $tags OSM tags for the accommodation
     */
    public function isLikelyOpen(DateTimeImmutable $date, array $tags): ?bool;
}
