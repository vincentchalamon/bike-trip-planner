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
}
