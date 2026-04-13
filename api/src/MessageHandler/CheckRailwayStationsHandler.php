<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckRailwayStations;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private GeoDistanceInterface $haversine,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger);
    }

    public function __invoke(CheckRailwayStations $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::RAILWAY_STATIONS, function () use ($tripId, $stages, $locale): void {
            // Collect all stage endpoints (start + end of each stage)
            $endPoints = $this->collectEndpoints($stages);

            $query = $this->queryBuilder->buildRailwayStationQuery($endPoints);
            $result = $this->scanner->query($query);

            /** @var list<array{lat?: float, lon?: float, center?: array{lat: float, lon: float}, tags?: array<string, string>}> $elements */
            $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];

            // Parse station locations
            $stationLocations = [];
            foreach ($elements as $element) {
                $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
                $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

                if (null === $lat || null === $lon) {
                    continue;
                }

                $name = $element['tags']['name'] ?? null;
                $stationLocations[] = ['lat' => (float) $lat, 'lon' => (float) $lon, 'name' => $name];
            }

            // Check each stage for nearby stations and build alerts
            $alerts = [];
            foreach ($stages as $i => $stage) {
                if ($this->hasNearbyStation($stage, $stationLocations)) {
                    continue;
                }

                // Find the nearest station across the entire trip for navigation
                $nearestStation = $this->findNearestStation($stage->endPoint, $stationLocations);

                $alert = [
                    'stageIndex' => $i,
                    'dayNumber' => $stage->dayNumber,
                    'type' => AlertType::NUDGE->value,
                    'message' => $this->translator->trans(
                        'alert.railway_station.nudge',
                        ['%stage%' => $stage->dayNumber],
                        'alerts',
                        $locale,
                    ),
                ];

                if (null !== $nearestStation) {
                    $alert['action'] = 'navigate';
                    $alert['actionLat'] = $nearestStation['lat'];
                    $alert['actionLon'] = $nearestStation['lon'];
                    $alert['stationName'] = $nearestStation['name'];
                }

                $alerts[] = $alert;
            }

            $this->publisher->publish($tripId, MercureEventType::RAILWAY_STATION_ALERTS, [
                'alerts' => $alerts,
            ]);
        }, $generation);
    }

    /**
     * Collects start and end points from all stages (deduplicated).
     *
     * @param list<Stage> $stages
     *
     * @return list<Coordinate>
     */
    private function collectEndpoints(array $stages): array
    {
        $points = [];
        foreach ($stages as $stage) {
            $points[] = $stage->startPoint;
            $points[] = $stage->endPoint;
        }

        return $points;
    }

    /**
     * Checks whether a station is within proximity of either endpoint of the stage.
     *
     * @param list<array{lat: float, lon: float, name: string|null}> $stationLocations
     */
    private function hasNearbyStation(Stage $stage, array $stationLocations): bool
    {
        foreach ($stationLocations as $station) {
            $distToStart = $this->haversine->inMeters($stage->startPoint->lat, $stage->startPoint->lon, $station['lat'], $station['lon']);
            $distToEnd = $this->haversine->inMeters($stage->endPoint->lat, $stage->endPoint->lon, $station['lat'], $station['lon']);

            if ($distToStart < self::STATION_PROXIMITY_METERS || $distToEnd < self::STATION_PROXIMITY_METERS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds the nearest station to a given point across all discovered stations.
     *
     * @param list<array{lat: float, lon: float, name: string|null}> $stationLocations
     *
     * @return array{lat: float, lon: float, name: string|null}|null
     */
    private function findNearestStation(Coordinate $point, array $stationLocations): ?array
    {
        if ([] === $stationLocations) {
            return null;
        }

        $minDist = PHP_FLOAT_MAX;
        $nearest = null;

        foreach ($stationLocations as $station) {
            $dist = $this->haversine->inMeters($point->lat, $point->lon, $station['lat'], $station['lon']);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $station;
            }
        }

        return $nearest;
    }
}
