<?php

declare(strict_types=1);

namespace App\Enum;

enum ComputationName: string
{
    case ROUTE = 'route';
    case STAGES = 'stages';
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
    case FERRIES = 'ferries';
    case FORDS = 'fords';
    case EVENTS = 'events';

    /**
     * Computations initialized at trip creation (the main pipeline).
     * On-demand computations (ROUTE_SEGMENT) are excluded.
     *
     * @return list<self>
     */
    public static function pipeline(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $c): bool => self::ROUTE_SEGMENT !== $c,
        ));
    }

    /**
     * The structural computations that make a trip renderable (ADR-043).
     *
     * Pure local CPU work (route parsing + pacing). Used to express the intent
     * behind posting the persisted `ready` status — it does NOT fragment the
     * {@see self::pipeline()} tracker, whose completion still gates the terminal
     * `AllEnrichmentsCompleted` / TripCompletionGate event.
     *
     * @return list<self>
     */
    public static function structuralPipeline(): array
    {
        return [self::ROUTE, self::STAGES];
    }

    /**
     * What has to change for this computation's result to be wrong (ADR-070).
     *
     * The single source of truth behind every re-dispatch decision. It used to be spread over
     * four partial lists that disagreed with each other — which is how a stage merge left
     * seven groups holding alerts computed against the old line, and a change of start date
     * left the sunset alert on the old date.
     *
     * Most enrichments depend on both: a resupply scan reads the corridor *and* the weekday
     * the rider passes, an accommodation scan the end point *and* the month. Splitting these
     * into two disjoint sets is what made the earlier lists wrong.
     *
     * @return list<ComputationTrigger>
     */
    public function triggers(): array
    {
        return match ($this) {
            self::POIS, self::ACCOMMODATIONS, self::TERRAIN, self::EVENTS, self::WEATHER => [ComputationTrigger::GEOMETRY, ComputationTrigger::DATES],
            self::BIKE_SHOPS, self::WATER_POINTS, self::HEALTH_SERVICES, self::RAILWAY_STATIONS,
            self::CULTURAL_POIS, self::BORDER_CROSSING, self::FERRIES => [ComputationTrigger::GEOMETRY],
            self::CALENDAR => [ComputationTrigger::DATES],
            // Cascaded by FetchWeatherHandler once the forecast lands, so they follow WEATHER
            // rather than being dispatched on their own.
            self::WIND, self::FORDS => [],
            // Root computations and on-demand work, not enrichments.
            self::ROUTE, self::STAGES, self::ROUTE_SEGMENT => [],
        };
    }

    /**
     * Every enrichment that any of these triggers invalidates.
     *
     * @return list<self>
     */
    public static function dependingOn(ComputationTrigger ...$triggers): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $c): bool => array_any(
                $c->triggers(),
                static fn (ComputationTrigger $t): bool => \in_array($t, $triggers, true),
            ),
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
            self::POIS => 'points_of_interest',
            self::ACCOMMODATIONS => 'accommodations',
            self::TERRAIN, self::BIKE_SHOPS, self::WATER_POINTS,
            self::HEALTH_SERVICES, self::RAILWAY_STATIONS, self::BORDER_CROSSING, self::FERRIES, self::FORDS => 'terrain_security',
            self::WEATHER, self::WIND => 'weather',
            self::CALENDAR, self::EVENTS, self::CULTURAL_POIS => 'context',
        };
    }
}
