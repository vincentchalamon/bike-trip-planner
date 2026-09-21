<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What can make a computed enrichment wrong.
 *
 * Since ADR-068 an enrichment survives the edit that follows it, so "wrong" no longer fixes
 * itself: whatever a computation read, something has to notice when that input moves. These
 * are the two things that move (ADR-070).
 *
 * Deliberately not a list of *fields*: `startDate` and a rest-day insertion both shift the
 * dates, by different routes, and a caller should not have to know which computations care.
 */
enum ComputationTrigger
{
    /** The stage line, its end points, or the corridor drawn around it. */
    case GEOMETRY;

    /** The calendar date a stage falls on, from `startDate` plus its day number. */
    case DATES;
}
