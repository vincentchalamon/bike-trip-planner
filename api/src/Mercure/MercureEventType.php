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
    case WATER_POINT_ALERTS = 'water_point_alerts';
    case SUPPLY_TIMELINE = 'supply_timeline';
    case ROUTE_SEGMENT_RECALCULATED = 'route_segment_recalculated';
    case CULTURAL_POI_ALERTS = 'cultural_poi_alerts';
    case RAILWAY_STATION_ALERTS = 'railway_station_alerts';
    case HEALTH_SERVICE_ALERTS = 'health_service_alerts';
    case BORDER_CROSSING_ALERTS = 'border_crossing_alerts';
    case EVENTS_FOUND = 'events_found';
    case VALIDATION_ERROR = 'validation_error';
    case COMPUTATION_ERROR = 'computation_error';
    case TRIP_COMPLETE = 'trip_complete';
    /**
     * Progress-only event published by every handler when it finishes.
     *
     * Used during Act 2 (initial analysis) to drive a narrative progress bar
     * without leaking business payloads — only `{ step, category, completed, total }`.
     */
    case COMPUTATION_STEP_COMPLETED = 'computation_step_completed';
    /**
     * Single terminal event published once when the full analysis pipeline has settled.
     *
     * Carries the fully enriched trip payload (stages, weather, alerts, accommodations,
     * events, supply timeline, AI analysis) so the frontend can swap the trip state
     * atomically and avoid layout shift.
     */
    case TRIP_READY = 'trip_ready';
    /**
     * Per-stage update event published during Act 3 (inline modifications).
     *
     * Only the updated stage is sent so the frontend mutates a single slice
     * of the store instead of replacing the whole trip.
     */
    case STAGE_UPDATED = 'stage_updated';
}
