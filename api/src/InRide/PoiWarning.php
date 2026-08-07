<?php

declare(strict_types=1);

namespace App\InRide;

/**
 * Typed diagnostic surfaced on a {@see PoiSuggestion}, replacing the free-text
 * French strings the deleted InRideAssistant wrote straight into the API
 * `warning` field. The frontend maps each case to a localised message, so the
 * backend never emits natural language here.
 *
 * - CLOSES_SOON: the venue is open but shuts within 30 minutes; carried
 *   together with {@see PoiSuggestion::$warningMinutes}.
 * - FAR_FROM_ROUTE: the POI sits past {@see DetourCalculator::POI_FAR_THRESHOLD_METERS}
 *   from the remaining route ({@see DetourResult::$poiFarFromRoute}).
 * - HOURS_UNVERIFIED: no readable `opening_hours` tag, so the POI is kept but
 *   its schedule is unconfirmed.
 */
enum PoiWarning: string
{
    case CLOSES_SOON = 'closes_soon';
    case FAR_FROM_ROUTE = 'far_from_route';
    case HOURS_UNVERIFIED = 'hours_unverified';
}
