<?php

declare(strict_types=1);

namespace App\Enum;

enum ComputationName: string
{
    case ROUTE = 'route';
    case STAGES = 'stages';
    case OSM_SCAN = 'osm_scan';
    case POIS = 'pois';
    case ACCOMMODATIONS = 'accommodations';
    case TERRAIN = 'terrain';
    case WEATHER = 'weather';
    case CALENDAR = 'calendar';
    case WIND = 'wind';
    case BIKE_SHOPS = 'bike_shops';
    case WATER_POINTS = 'water_points';
    case ROUTE_SEGMENT = 'route_segment';
    case CULTURAL_POIS = 'cultural_pois';
    case RAILWAY_STATIONS = 'railway_stations';
    case HEALTH_SERVICES = 'health_services';
    case BORDER_CROSSING = 'border_crossing';
    case EVENTS = 'events';
    /**
     * LLaMA 8B pass-1 — per-stage AI analysis (issue #303).
     *
     * Dispatched once for every non-rest stage after the enrichment gate fires.
     * Tracked separately from the main pipeline so it does not influence the
     * `AllEnrichmentsCompleted` gate; it only feeds the AI progress category.
     */
    case STAGE_AI_ANALYSIS = 'stage_ai_analysis';
    /**
     * LLaMA 8B pass-2 — trip-level overview synthesis (issue #303).
     *
     * Dispatched once every pass-1 has settled. Used solely for progress reporting.
     */
    case TRIP_AI_OVERVIEW = 'trip_ai_overview';

    /**
     * Computations initialized at trip creation (the main pipeline).
     * On-demand computations (ROUTE_SEGMENT) and post-pipeline LLM analyses
     * (STAGE_AI_ANALYSIS, TRIP_AI_OVERVIEW) are excluded.
     *
     * @return list<self>
     */
    public static function pipeline(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $c): bool => !\in_array($c, [
                self::ROUTE_SEGMENT,
                self::STAGE_AI_ANALYSIS,
                self::TRIP_AI_OVERVIEW,
            ], true),
        ));
    }

    /**
     * Returns the user-facing progress category this computation belongs to.
     *
     * The category groups several individual computations under the same progress
     * label displayed to the user during Act 2 (e.g. "terrain_security" covers
     * terrain analysis, bike shops, water points, etc.).
     */
    public function category(): string
    {
        return match ($this) {
            self::ROUTE, self::STAGES, self::ROUTE_SEGMENT => 'route',
            self::OSM_SCAN, self::POIS => 'points_of_interest',
            self::ACCOMMODATIONS => 'accommodations',
            self::TERRAIN, self::BIKE_SHOPS, self::WATER_POINTS,
            self::HEALTH_SERVICES, self::RAILWAY_STATIONS, self::BORDER_CROSSING => 'terrain_security',
            self::WEATHER, self::WIND => 'weather',
            self::CALENDAR, self::EVENTS, self::CULTURAL_POIS => 'context',
            self::STAGE_AI_ANALYSIS, self::TRIP_AI_OVERVIEW => 'ai_analysis',
        };
    }
}
