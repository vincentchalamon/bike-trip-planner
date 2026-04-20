<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\TripRequest;
use App\Message\AnalyzeTerrain;
use App\Message\CheckBikeShops;
use App\Message\CheckBorderCrossing;
use App\Message\CheckCalendar;
use App\Message\CheckCulturalPois;
use App\Message\CheckHealthServices;
use App\Message\CheckRailwayStations;
use App\Message\CheckWaterPoints;
use App\Message\FetchWeather;
use App\Message\ScanAccommodations;
use App\Message\ScanEvents;
use App\Message\ScanPois;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches all enrichment messages for a trip once its stages have been generated.
 *
 * Centralises the fan-out previously inlined in {@see \App\MessageHandler\GenerateStagesHandler}
 * so that the same pipeline can be triggered from:
 *  - the stage-generation handler (automatic flow, Act 1),
 *  - the dedicated `POST /trips/{id}/analyze` endpoint (explicit flow, Act 2).
 */
final readonly class TripAnalysisDispatcher
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Dispatches every enrichment message for the given trip.
     *
     * @param int|null $generation Current trip generation. When provided, stale workers can discard
     *                             outdated messages. Pass null for fire-and-forget pipelines.
     */
    public function dispatch(string $tripId, TripRequest $request, ?int $generation = null): void
    {
        $this->messageBus->dispatch(new ScanAllOsmData($tripId, $generation));
        $this->messageBus->dispatch(new ScanPois($tripId, $generation));
        $this->messageBus->dispatch(new ScanAccommodations(
            $tripId,
            enabledAccommodationTypes: $request->enabledAccommodationTypes,
            generation: $generation,
        ));
        $this->messageBus->dispatch(new AnalyzeTerrain($tripId, $generation));
        $this->messageBus->dispatch(new FetchWeather($tripId, $generation));
        $this->messageBus->dispatch(new CheckCalendar($tripId, $generation));
        $this->messageBus->dispatch(new CheckBikeShops($tripId, $generation));
        $this->messageBus->dispatch(new CheckWaterPoints($tripId, $generation));
        $this->messageBus->dispatch(new CheckHealthServices($tripId, $generation));
        $this->messageBus->dispatch(new CheckCulturalPois($tripId, $generation));
        $this->messageBus->dispatch(new CheckRailwayStations($tripId, $generation));
        $this->messageBus->dispatch(new CheckBorderCrossing($tripId, $generation));
        $this->messageBus->dispatch(new ScanEvents($tripId, $generation));
    }
}
