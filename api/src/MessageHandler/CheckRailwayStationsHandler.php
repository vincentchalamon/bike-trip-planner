<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Geo\Nearest;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\Alert;
use App\Alert\AlertPayload;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertCode;
use App\Enum\AlertParameterFormat;
use App\Enum\AlertGroup;
use App\Enum\AlertType;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Message\CheckRailwayStations;
use App\Osm\RailwayStationRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Checks for railway stations within 10 km of each stage endpoint.
 *
 * Generates a nudge alert when no station is reachable, with a `navigate`
 * action pointing to the nearest station found across the entire trip.
 * This gives cyclists an emergency evacuation option in case of mechanical
 * failure, injury, or extreme weather.
 */
#[AsMessageHandler]
final readonly class CheckRailwayStationsHandler extends AbstractTripMessageHandler
{
    private const int STATION_PROXIMITY_METERS = 10_000;

    public function __construct(
        TripHandlerContext $context,
        private RailwayStationRepositoryInterface $railwayStationRepository,
        private GeoDistanceInterface $haversine,
    ) {
        parent::__construct($context);
    }

    public function __invoke(CheckRailwayStations $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->stageStore->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $this->executeWithTracking($message, function () use ($tripId, $stages): void {
            // Collect all stage endpoints (start + end of each stage)
            $endPoints = $this->collectEndpoints($stages);

            if ([] === $endPoints) {
                // Nothing found is a result, not an absence of one: the group is cleared so a
                // previous run's alerts do not survive as stale.
                $this->stageStore->updateTripAlertsForGroup($tripId, AlertGroup::RAILWAY_STATION, []);
                $this->publisher->publish($tripId, MercureEventType::RAILWAY_STATION_ALERTS, ['alerts' => []]);

                return;
            }

            // Read railway stations from the local-first index near the stage endpoints (ADR-040).
            $route = array_map(static fn (Coordinate $point): array => $point->toLatLon(), $endPoints);

            $stationLocations = [];
            foreach ($this->railwayStationRepository->findInCorridor($route, self::STATION_PROXIMITY_METERS) as $station) {
                $stationLocations[] = ['lat' => $station['lat'], 'lon' => $station['lon']];
            }

            // Check each stage for nearby stations and build alerts
            $alerts = [];
            foreach ($stages as $stage) {
                if ($stage->isRestDay) {
                    continue;
                }

                if (
                    Nearest::anyWithin($this->haversine, $stage->startPoint, $stationLocations, self::STATION_PROXIMITY_METERS)
                    || Nearest::anyWithin($this->haversine, $stage->endPoint, $stationLocations, self::STATION_PROXIMITY_METERS)
                ) {
                    continue;
                }

                // Find the nearest station across the entire trip for navigation
                $nearestStation = Nearest::to($this->haversine, $stage->endPoint, $stationLocations);

                $alerts[] = AlertPayload::forStage($stage, new Alert(
                    code: AlertCode::RAILWAY_STATION_NONE_NEARBY,
                    type: AlertType::NUDGE,
                    messageKey: 'alert.railway_station.nudge',
                    parameters: ['%threshold%' => self::STATION_PROXIMITY_METERS],
                    parameterFormats: ['%threshold%' => AlertParameterFormat::DISTANCE->value],
                    lat: $nearestStation['lat'] ?? null,
                    lon: $nearestStation['lon'] ?? null,
                    action: null !== $nearestStation
                        ? new AlertAction(AlertActionKind::NAVIGATE, 'alert.railway_station.action', ['lat' => $nearestStation['lat'], 'lon' => $nearestStation['lon']])
                        : null,
                ));
            }

            // Same array to the database and to the wire (ADR-068): grouped by the stage
            // it addresses, without `stageId`/`dayNumber` — the first is the key, the
            // second is renumbered by every structural edit and is derived on read.
            $this->stageStore->updateTripAlertsForGroup($tripId, AlertGroup::RAILWAY_STATION, $this->groupByStage($alerts));

            $this->publisher->publish($tripId, MercureEventType::RAILWAY_STATION_ALERTS, [
                'alerts' => $this->renderForWire($tripId, $alerts),
            ]);
        });
    }

    /**
     * Collects start and end points from all stages.
     *
     * @param list<Stage> $stages
     *
     * @return list<Coordinate>
     */
    private function collectEndpoints(array $stages): array
    {
        $points = [];
        foreach ($stages as $stage) {
            if ($stage->isRestDay) {
                continue;
            }

            $points[] = $stage->startPoint;
            $points[] = $stage->endPoint;
        }

        return $points;
    }
}
