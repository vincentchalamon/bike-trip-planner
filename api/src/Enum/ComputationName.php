<?php

declare(strict_types=1);

namespace App\Enum;

enum ComputationName: string
{
    case ROUTE = 'route';
    case STAGES = 'stages';
    case STAGE_GPX = 'stage_gpx';
    case POIS = 'pois';
    case ACCOMMODATIONS = 'accommodations';
    case TERRAIN = 'terrain';
    case WEATHER = 'weather';
    case CALENDAR = 'calendar';
    case WIND = 'wind';
    case BIKE_SHOPS = 'bike_shops';
}
