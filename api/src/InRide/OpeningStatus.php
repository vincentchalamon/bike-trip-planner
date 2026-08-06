<?php

declare(strict_types=1);

namespace App\InRide;

/**
 * Tri-state opening verdict produced by {@see OpeningHoursParser::status()}.
 *
 * `UNKNOWN` keeps a POI whose tag says nothing (unreadable or empty) visible
 * with a warning, instead of hiding it as if it were closed.
 */
enum OpeningStatus
{
    case OPEN;
    case CLOSED;
    case UNKNOWN;
}
