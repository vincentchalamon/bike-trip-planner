<?php

declare(strict_types=1);

namespace App\Osm;

/**
 * The point tables {@see CorridorPointQuery} may read. An enum rather than a string so the
 * table name interpolated into the SQL can only ever be one of these.
 */
enum CorridorPointTable: string
{
    case RAILWAY_STATIONS = 'osm.railway_stations';
    case HEALTH_SERVICES = 'osm.health_services';
    case WATER_POINTS = 'osm.water_points';
}
