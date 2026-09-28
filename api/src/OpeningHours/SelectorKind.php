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
    /** `dec 25`: the only date selector, and only as a rule's whole selector. */
    case MONTH_DAY;
    /** Nothing between two commas (`Mo,,Tu`). */
    case EMPTY;
    /** Any token the grammar does not model (`Su[1]`, `week 1-53`, `Apr-Oct`...). */
    case UNKNOWN;
}
