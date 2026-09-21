<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ComputationName;
use App\Message\AnalyzeTerrain;
use App\Message\CheckBikeShops;
use App\Message\CheckBorderCrossing;
use App\Message\CheckCalendar;
use App\Message\CheckCulturalPois;
use App\Message\CheckFerries;
use App\Message\CheckHealthServices;
use App\Message\CheckRailwayStations;
use App\Message\CheckWaterPoints;
use App\Message\FetchWeather;
use App\Message\ScanAccommodations;
use App\Message\ScanEvents;
use App\Message\ScanPois;

/**
 * The message a computation is carried by.
 *
 * One mapping, because the three callers that used to hold their own had drifted: a PATCH
 * could dispatch a computation a structural edit never did, and neither matched the full
 * pipeline (ADR-070).
 */
final readonly class EnrichmentMessageFactory
{
    /**
     * @param list<string> $enabledAccommodationTypes
     *
     * @throws \LogicException for a computation that is not an enrichment, or one that is
     *                         cascaded rather than dispatched — silently skipping it is how
     *                         the last gap stayed invisible
     */
    public function create(
        ComputationName $computation,
        string $tripId,
        ?int $generation = null,
        array $enabledAccommodationTypes = [],
    ): object {
        return match ($computation) {
            ComputationName::POIS => new ScanPois($tripId, $generation),
            ComputationName::ACCOMMODATIONS => new ScanAccommodations(
                $tripId,
                enabledAccommodationTypes: $enabledAccommodationTypes,
                generation: $generation,
            ),
            ComputationName::TERRAIN => new AnalyzeTerrain($tripId, $generation),
            ComputationName::WEATHER => new FetchWeather($tripId, $generation),
            ComputationName::CALENDAR => new CheckCalendar($tripId, $generation),
            ComputationName::BIKE_SHOPS => new CheckBikeShops($tripId, $generation),
            ComputationName::WATER_POINTS => new CheckWaterPoints($tripId, $generation),
            ComputationName::HEALTH_SERVICES => new CheckHealthServices($tripId, $generation),
            ComputationName::CULTURAL_POIS => new CheckCulturalPois($tripId, $generation),
            ComputationName::RAILWAY_STATIONS => new CheckRailwayStations($tripId, $generation),
            ComputationName::BORDER_CROSSING => new CheckBorderCrossing($tripId, $generation),
            ComputationName::FERRIES => new CheckFerries($tripId, $generation),
            ComputationName::EVENTS => new ScanEvents($tripId, $generation),
            default => throw new \LogicException(\sprintf('No enrichment message registered for computation "%s". WIND and FORDS are cascaded by FetchWeatherHandler once the forecast lands; ROUTE, STAGES and ROUTE_SEGMENT are not enrichments.', $computation->value)),
        };
    }
}
