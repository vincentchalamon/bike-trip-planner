<?php

declare(strict_types=1);

namespace App\OpeningHours;

/**
 * The state keyword of a rule: `off` and `closed` are synonyms.
 */
enum Modifier
{
    case OFF;
    case OPEN;
}
