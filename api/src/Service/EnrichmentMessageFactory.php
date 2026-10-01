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
use App\Message\TracksComputation;

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
     * The messages an enrichment is dispatched by. Each declares its own computation, so this
     * lists them without mapping them a second time.
     *
     * @var list<class-string<TracksComputation>>
     */
    private const array MESSAGES = [
        ScanPois::class,
        ScanAccommodations::class,
        AnalyzeTerrain::class,
        FetchWeather::class,
        CheckCalendar::class,
        CheckBikeShops::class,
        CheckWaterPoints::class,
        CheckHealthServices::class,
        CheckCulturalPois::class,
        CheckRailwayStations::class,
        CheckBorderCrossing::class,
        CheckFerries::class,
        ScanEvents::class,
    ];

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
    ): TracksComputation {
        foreach (self::MESSAGES as $class) {
            if ($class::computation() !== $computation) {
                continue;
            }

            return ScanAccommodations::class === $class
                ? new ScanAccommodations($tripId, enabledAccommodationTypes: $enabledAccommodationTypes, generation: $generation)
                : new $class($tripId, $generation);
        }

        throw new \LogicException(\sprintf('No enrichment message registered for computation "%s". WIND and FORDS are cascaded by FetchWeatherHandler once the forecast lands; ROUTE, STAGES and ROUTE_SEGMENT are not enrichments.', $computation->value));
    }
}
