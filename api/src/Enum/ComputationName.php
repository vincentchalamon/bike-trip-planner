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
     * Computations initialized at trip creation (the main pipeline).
     * On-demand computations like ROUTE_SEGMENT are excluded.
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
        };
    }
}
