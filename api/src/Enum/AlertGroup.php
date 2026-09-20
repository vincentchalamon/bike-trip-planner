<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The producer an alert belongs to, and the unit in which alerts are replaced.
 *
 * A group is *not* a category for display: it names which computation owns those alerts, so
 * that re-running one producer replaces its own alerts and leaves the twelve others alone.
 * Without it every enrichment would either append forever or wipe its siblings — the bug
 * `replaceStageAlerts()` in `core/reconciliation.ts` was written to prevent.
 *
 * One case per producer, whatever severity or {@see AlertCode} the alerts carry: `ford`
 * covers both the dry and the wet ford variants, because a re-run of the ford check replaces
 * both.
 *
 * The list is contractual on both sides of the wire. {@see \App\ApiResource\TripDetail}
 * publishes {@see self::VALUES} into the OpenAPI schema, so `core/schema.d.ts` types the
 * field as a literal union and a group the server does not know fails the frontend build —
 * which is why no cross-language drift test is needed here.
 */
enum AlertGroup: string
{
    case TERRAIN = 'terrain';
    case POIS = 'pois';
    case ACCOMMODATIONS = 'accommodations';
    case CALENDAR = 'calendar';
    case WIND = 'wind';
    case BIKE_SHOP = 'bike_shop';
    case WATER_POINT = 'water_point';
    case HEALTH_SERVICE = 'health_service';
    case CULTURAL_POI = 'cultural_poi';
    case RAILWAY_STATION = 'railway_station';
    case BORDER_CROSSING = 'border_crossing';
    case FERRY = 'ferry';
    case FORD = 'ford';

    /** @var list<string> */
    public const array VALUES = [
        self::TERRAIN->value,
        self::POIS->value,
        self::ACCOMMODATIONS->value,
        self::CALENDAR->value,
        self::WIND->value,
        self::BIKE_SHOP->value,
        self::WATER_POINT->value,
        self::HEALTH_SERVICE->value,
        self::CULTURAL_POI->value,
        self::RAILWAY_STATION->value,
        self::BORDER_CROSSING->value,
        self::FERRY->value,
        self::FORD->value,
    ];
}
