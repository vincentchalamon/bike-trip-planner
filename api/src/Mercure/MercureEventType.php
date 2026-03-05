<?php

declare(strict_types=1);

namespace App\Mercure;

enum MercureEventType: string
{
    case ROUTE_PARSED = 'route_parsed';
    case STAGES_COMPUTED = 'stages_computed';
    case POIS_SCANNED = 'pois_scanned';
    case ACCOMMODATIONS_FOUND = 'accommodations_found';
    case TERRAIN_ALERTS = 'terrain_alerts';
    case WEATHER_FETCHED = 'weather_fetched';
    case CALENDAR_ALERTS = 'calendar_alerts';
    case WIND_ALERTS = 'wind_alerts';
    case BIKE_SHOP_ALERTS = 'bike_shop_alerts';
    case VALIDATION_ERROR = 'validation_error';
    case COMPUTATION_ERROR = 'computation_error';
    case TRIP_COMPLETE = 'trip_complete';
}
