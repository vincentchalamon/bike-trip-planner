<?php

declare(strict_types=1);

namespace App\OpeningHours;

enum SelectorKind
{
    /** `Mo`, or a range `Mo-Fr` (wrapping past Sunday for `Fr-Mo`). */
    case WEEKDAYS;
    /** `PH`. */
    case PUBLIC_HOLIDAY;
    /** `SH`. */
    case SCHOOL_HOLIDAY;
    /** `dec 25`: a single date, and only as a rule's whole selector. */
    case MONTH_DAY;
    /** `Apr-Oct`: a month range, wrapping past December for `Oct-Mar`. */
    case MONTH_RANGE;
    /** Nothing between two commas (`Mo,,Tu`). */
    case EMPTY;
    /** Any token the grammar does not model (`Su[1]`, `week 1-53`, `sunrise`...). */
    case UNKNOWN;
}
