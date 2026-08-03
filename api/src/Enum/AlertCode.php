<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Stable identifier of an alert rule variant.
 *
 * One case per *variant* actually emitted — not per family and not per
 * translation key. A rule that picks between several wordings for the same
 * condition (named vs unnamed public holiday, named vs unnamed cultural POI)
 * keeps a single code: the code identifies the rule, the translation key only
 * carries the phrasing. Conversely, two variants of the same family that differ
 * by threshold or severity (traffic main road vs secondary road, dry ford vs
 * wet ford) get distinct codes.
 *
 * The code is what the frontend keys dismissals and deduplication on, so
 * rewording a message must never change it. Every case must have exactly one
 * row in the README alert-engine table — `AlertDocumentationTest` enforces the
 * correspondence in both directions.
 */
enum AlertCode: string
{
    case CONTINUITY_GAP_CRITICAL = 'continuity_gap_critical';
    case CONTINUITY_GAP_WARNING = 'continuity_gap_warning';
    case ELEVATION_GAIN = 'elevation_gain';
    case STEEP_GRADIENT = 'steep_gradient';
    case SURFACE_ROUGH = 'surface_rough';
    case TRAFFIC_MAIN_ROAD = 'traffic_main_road';
    case TRAFFIC_SECONDARY_ROAD_FAST = 'traffic_secondary_road_fast';
    case TRAFFIC_SECONDARY_ROAD_SLOW = 'traffic_secondary_road_slow';
    case EBIKE_RANGE_EXCEEDED = 'ebike_range_exceeded';
    case SUNSET_ARRIVAL_AFTER_TWILIGHT = 'sunset_arrival_after_twilight';
    case CALENDAR_PUBLIC_HOLIDAY = 'calendar_public_holiday';
    case CALENDAR_SUNDAY = 'calendar_sunday';
    case WIND_HEADWIND = 'wind_headwind';
    case COMFORT_POOR_CONDITIONS = 'comfort_poor_conditions';
    case BIKE_SHOP_NONE_NEARBY = 'bike_shop_none_nearby';
    case RESUPPLY_NONE_ON_STAGE = 'resupply_none_on_stage';
    case RESUPPLY_CLOSED_AT_PASSAGE = 'resupply_closed_at_passage';
    case ACCOMMODATION_SEASONAL_CLOSURE = 'accommodation_seasonal_closure';
    case WATER_POINT_GAP = 'water_point_gap';
    case REST_DAY_SUGGESTED = 'rest_day_suggested';
    case CULTURAL_POI_SUGGESTION = 'cultural_poi_suggestion';
    case RAILWAY_STATION_NONE_NEARBY = 'railway_station_none_nearby';
    case HEALTH_SERVICE_NONE_NEARBY = 'health_service_none_nearby';
    case BORDER_CROSSING = 'border_crossing';
    case FERRY_CROSSING = 'ferry_crossing';
    case FORD_CROSSING_DRY = 'ford_crossing_dry';
    case FORD_CROSSING_WET = 'ford_crossing_wet';
}
